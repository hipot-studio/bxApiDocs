<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Everything the JSON Schema of design §2.6 cannot express, plus the dataset gates of §6.5.
 *
 * The portal never relies on the plugin having checked already: a request the plugin had no right
 * to send is rejected here. The rules mirror the plugin side exactly, so a disagreement surfaces as
 * an error instead of a wrong number.
 */
final class Validator
{
	private const TRANSFORMABLE_TYPES = [TypeSpec::DATE, TypeSpec::TIMESTAMP];

	/** Units that keep a DATE expression a DATE (design §3.3). */
	private const DATE_PRESERVING_UNITS = [
		DateTruncSpec::UNIT_DAY,
		DateTruncSpec::UNIT_WEEK,
		DateTruncSpec::UNIT_MONTH,
		DateTruncSpec::UNIT_QUARTER,
		DateTruncSpec::UNIT_YEAR,
	];

	public function __construct(
		private readonly DatasetSchema $schema,
		private readonly Capabilities $capabilities,
	)
	{
	}

	/**
	 * @throws AggregationException
	 */
	public function validate(Request $request, string $requestedTable): void
	{
		$this->validateDatasetGates($request);

		if ($request->table !== $requestedTable)
		{
			throw AggregationException::badRequest('from.table does not match the requested table');
		}

		if ($request->groupBy === [] && $request->aggregates === [])
		{
			throw AggregationException::badRequest('groupBy and aggregates cannot both be empty');
		}

		$aliases = [];
		foreach ($request->groupBy as $position => $item)
		{
			$this->validateGroupItem($request, $position, $item);
			$this->rememberAlias($aliases, $item->alias);
		}

		foreach ($request->aggregates as $position => $item)
		{
			$this->validateAggregateItem($request, $position, $item);
			$this->rememberAlias($aliases, $item->alias);
		}

		$this->validateHaving($request);
		$this->validateOrderBy($request);
		$this->validateGroupLimit($request);
	}

	private function validateDatasetGates(Request $request): void
	{
		if (!$this->capabilities->supported)
		{
			throw AggregationException::unsupported('aggregation is not available for this dataset');
		}

		if (!in_array($request->version, $this->capabilities->protocolVersions, true))
		{
			throw new AggregationException(ErrorCode::UNSUPPORTED_VERSION, (string)$request->version);
		}

		// Re-checked per request: capabilities may have been fetched before the dataset changed.
		if ($this->schema->getRowSetSemantics() !== DatasetSchema::ROW_SET_RAW)
		{
			throw AggregationException::unsupported('row set semantics is not RAW');
		}
	}

	private function validateGroupItem(Request $request, int $position, GroupItem $item): void
	{
		$this->assertRelation($request, $item->relation);
		$inputType = $this->resolveFieldType($item->field);

		$expected = $item->transform === null
			? $this->untransformedKeyType($inputType)
			: $this->transformedKeyType($item, $inputType)
		;

		if (!$item->outputType->equals($expected))
		{
			throw AggregationException::typeMismatch(
				"group key {$item->field}: expected {$expected}, got {$item->outputType}"
			);
		}

		$this->assertSemanticSupport($inputType, $item->field);

		$alias = AliasGenerator::forGroupKey($position, $item->relation, $item->field, $item->transform);
		if ($alias !== $item->alias)
		{
			throw AggregationException::badRequest("group key alias mismatch: expected {$alias}");
		}
	}

	private function untransformedKeyType(TypeSpec $inputType): TypeSpec
	{
		$expected = TypeMatrix::groupKeyOutputType($inputType);
		if ($expected === null)
		{
			throw AggregationException::typeMismatch("type {$inputType} cannot be a grouping key");
		}

		return $expected;
	}

	private function transformedKeyType(GroupItem $item, TypeSpec $inputType): TypeSpec
	{
		if (!in_array($inputType->base, self::TRANSFORMABLE_TYPES, true))
		{
			throw AggregationException::typeMismatch("field {$item->field} of type {$inputType} cannot be truncated");
		}

		$transform = $item->transform;
		if (!$this->capabilities->supportsTruncUnit($transform->unit))
		{
			throw AggregationException::badRequest("unit {$transform->unit} is not supported");
		}

		// A DATE column carries no time of day, and the source will not invent one: truncation to an
		// hour, a minute or a second is not something it can perform over such a column, whatever the
		// requested output type is. The capability does declare those units, and truthfully - for a
		// timestamp. Accepting the pair here would compile a statement the source rejects, and the
		// answer would be an error instead of the number the client could have computed itself.
		if (
			$inputType->base === TypeSpec::DATE
			&& !in_array($transform->unit, self::DATE_PRESERVING_UNITS, true)
		)
		{
			throw AggregationException::typeMismatch(
				"field {$item->field} of type DATE cannot be truncated to {$transform->unit}"
			);
		}

		$this->assertTransformShape($transform);

		// The precision of a transformed key is decided by the query expression, usually 3 because
		// Superset casts the column to TIMESTAMP; the wire format is the same for every precision
		// (design §3.3), so the portal only checks the range, which TypeSpec already did.
		if ($item->outputType->base === TypeSpec::TIMESTAMP)
		{
			return $item->outputType;
		}

		if (
			$item->outputType->base === TypeSpec::DATE
			&& $inputType->base === TypeSpec::DATE
			&& in_array($transform->unit, self::DATE_PRESERVING_UNITS, true)
			&& !$transform->hasBucket()
			&& !$transform->hasOffsets()
		)
		{
			return $item->outputType;
		}

		throw AggregationException::typeMismatch(
			"transformed key {$item->field} cannot produce {$item->outputType}"
		);
	}

	private function assertTransformShape(DateTruncSpec $transform): void
	{
		if ($transform->hasBucket())
		{
			$divisor = DateTruncSpec::BUCKET_DIVISORS[$transform->unit] ?? null;
			if ($divisor === null)
			{
				throw AggregationException::badRequest("unit {$transform->unit} does not accept buckets");
			}

			if ($divisor % $transform->bucket !== 0)
			{
				throw AggregationException::badRequest(
					"bucket {$transform->bucket} does not divide {$divisor}"
				);
			}
		}

		if (
			$transform->hasOffsets()
			&& ($transform->unit !== DateTruncSpec::UNIT_WEEK || $transform->hasBucket())
		)
		{
			throw AggregationException::badRequest('day offsets are allowed for WEEK with bucket 1 only');
		}
	}

	private function validateAggregateItem(Request $request, int $position, AggregateItem $item): void
	{
		$this->assertRelation($request, $item->relation);

		if ($item->field === null && $item->function !== AggregateItem::COUNT)
		{
			throw AggregationException::badRequest("{$item->function} requires a field");
		}

		$inputType = $item->field === null ? null : $this->resolveFieldType($item->field);

		if (!$this->capabilities->supportsFunction($item->function, $item->distinct))
		{
			throw AggregationException::badRequest("function {$item->function} is not supported");
		}

		$types = TypeMatrix::aggregateTypes($item->function, $item->distinct, $inputType);
		if ($types === null)
		{
			$input = $inputType === null ? '*' : (string)$inputType;

			throw AggregationException::typeMismatch("{$item->function} over {$input} is outside the matrix");
		}

		if (!$item->outputType->equals($types['outputType']))
		{
			throw AggregationException::typeMismatch(
				"{$item->function}: expected output {$types['outputType']}, got {$item->outputType}"
			);
		}

		$this->assertWireType($item, $types['wireType']);

		if ($inputType !== null && $item->field !== null)
		{
			$this->assertSemanticSupport($inputType, $item->field);
		}

		$alias = AliasGenerator::forAggregate(
			$position,
			$item->relation,
			$item->field,
			$item->function,
			$item->distinct,
		);
		if ($alias !== $item->alias)
		{
			throw AggregationException::badRequest("aggregate alias mismatch: expected {$alias}");
		}
	}

	private function assertWireType(AggregateItem $item, ?TypeSpec $expected): void
	{
		if ($expected === null)
		{
			// AVG travels as a pair, so a single wire type would be meaningless.
			if ($item->wireType !== null)
			{
				throw AggregationException::badRequest('AVG must not carry a wireType');
			}

			return;
		}

		if ($item->wireType === null)
		{
			throw AggregationException::badRequest("{$item->function} requires a wireType");
		}

		if (!$item->wireType->equals($expected))
		{
			throw AggregationException::typeMismatch(
				"{$item->function}: expected wire {$expected}, got {$item->wireType}"
			);
		}
	}

	/**
	 * Semantic flags the cell depends on (design §3.1): byte-wise string operations and a
	 * normalizable boolean carrier.
	 */
	private function assertSemanticSupport(TypeSpec $type, string $field): void
	{
		$semantics = $this->capabilities->semantics;

		if (TypeMatrix::requiresBinaryStrings($type) && $semantics?->binaryStringOperations !== true)
		{
			throw AggregationException::unsupportedField("{$field}: byte-wise string operations are unavailable");
		}

		if ($type->base === TypeSpec::BOOLEAN && $this->capabilities->isColumnExcluded($field))
		{
			throw AggregationException::unsupportedField("{$field}: boolean carrier is not normalizable");
		}
	}

	private function validateHaving(Request $request): void
	{
		if ($request->having === null)
		{
			return;
		}

		if (!$this->capabilities->having)
		{
			throw AggregationException::badRequest('HAVING is not supported');
		}

		$this->validateHavingNode($request, $request->having);
	}

	private function validateHavingNode(Request $request, HavingNode $node): void
	{
		foreach ($node->args as $child)
		{
			$this->validateHavingNode($request, $child);
		}

		if ($node->arg !== null)
		{
			$this->validateHavingNode($request, $node->arg);
		}

		if ($node->ref === null)
		{
			return;
		}

		$referenceType = $this->resolveAliasType($request, $node->ref);
		$this->assertOrderableOrComparable($referenceType, $node->ref);

		foreach ($node->collectLiterals() as $literal)
		{
			if (!$literal->type->equals($referenceType))
			{
				throw AggregationException::typeMismatch(
					"literal type {$literal->type} does not match reference {$node->ref} of type {$referenceType}"
				);
			}

			LiteralGrammar::assertValid($literal->type, $literal->value);
		}
	}

	private function validateOrderBy(Request $request): void
	{
		if ($request->orderBy === [])
		{
			return;
		}

		if (!$this->capabilities->orderBy)
		{
			throw AggregationException::badRequest('ORDER BY is not supported');
		}

		if ($request->groupLimit === null)
		{
			throw AggregationException::badRequest('a non-empty orderBy requires groupLimit');
		}

		$seen = [];
		foreach ($request->orderBy as $item)
		{
			if (isset($seen[$item->target]))
			{
				throw AggregationException::badRequest("duplicate orderBy target {$item->target}");
			}
			$seen[$item->target] = true;

			$type = $this->resolveAliasType($request, $item->target);
			$this->assertOrderableOrComparable($type, $item->target);

			if (
				TypeMatrix::requiresNanOrdering($type)
				&& $this->capabilities->semantics?->floatNanOrderingCompatible !== true
			)
			{
				throw AggregationException::badRequest("{$item->target}: NaN ordering is unavailable");
			}
		}
	}

	private function validateGroupLimit(Request $request): void
	{
		if ($request->groupLimit !== null && !$this->capabilities->groupLimit)
		{
			throw AggregationException::badRequest('groupLimit is not supported');
		}
	}

	private function assertOrderableOrComparable(TypeSpec $type, string $alias): void
	{
		if (
			TypeMatrix::requiresBinaryStrings($type)
			&& $this->capabilities->semantics?->binaryStringOperations !== true
		)
		{
			throw AggregationException::badRequest("{$alias}: byte-wise string operations are unavailable");
		}
	}

	private function resolveAliasType(Request $request, string $alias): TypeSpec
	{
		$item = $request->getItemsByAlias()[$alias] ?? null;
		if ($item === null)
		{
			throw AggregationException::badRequest("alias {$alias} is not declared in the block");
		}

		return $item->outputType;
	}

	private function resolveFieldType(string $field): TypeSpec
	{
		if (!$this->schema->hasField($field))
		{
			throw AggregationException::unsupportedField($field);
		}

		if ($this->capabilities->isColumnExcluded($field))
		{
			throw AggregationException::unsupportedField($field);
		}

		$type = TypeMatrix::inputType($this->schema->getFieldWireType($field));
		if ($type === null)
		{
			throw AggregationException::unsupportedField("{$field}: type takes no part in aggregation");
		}

		return $type;
	}

	private function assertRelation(Request $request, string $relation): void
	{
		if ($relation !== $request->relation)
		{
			throw AggregationException::badRequest("unknown relation {$relation}");
		}
	}

	private function rememberAlias(array &$aliases, string $alias): void
	{
		if (isset($aliases[$alias]))
		{
			throw AggregationException::badRequest("duplicate alias {$alias}");
		}

		$aliases[$alias] = true;
	}
}
