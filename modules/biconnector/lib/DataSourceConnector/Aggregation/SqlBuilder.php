<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\DB\BiAggregationDialectInterface;

/**
 * Generates the aggregated SQL (design §6.1).
 *
 * Everything dialect specific goes through BiAggregationDialectInterface; this class contains no
 * branching on the source type and never touches a concrete SqlHelper.
 *
 * Row set equivalence is preserved by reusing the field expressions, joins and WHERE of the raw
 * path: the aggregate is computed over exactly the rows the raw path would have returned.
 */
final class SqlBuilder
{
	/** @var array<string, string> alias => SQL expression, for HAVING and ORDER BY */
	private array $expressionByAlias = [];

	/** @var array<string, TypeSpec> alias => type of the value the expression produces */
	private array $typeByAlias = [];

	public function __construct(
		private readonly BiAggregationDialectInterface $dialect,
		private readonly DatasetSchema $schema,
		private readonly array $dateFormats,
	)
	{
	}

	/**
	 * @param string $whereClause Already built WHERE body, empty when there is no filter.
	 * @param string $joins Already built join list, empty when there are none.
	 */
	public function build(Request $request, string $whereClause = '', string $joins = ''): string
	{
		$this->expressionByAlias = [];
		$this->typeByAlias = [];

		$selectParts = [];
		$groupParts = [];

		foreach ($request->groupBy as $item)
		{
			$expression = $this->buildGroupExpression($item);
			$this->expressionByAlias[$item->alias] = $expression;
			$this->typeByAlias[$item->alias] = $item->outputType;

			$selectParts[] = $expression . ' AS ' . $this->dialect->quoteIdentifier($item->alias);
			$groupParts[] = $expression;
		}

		foreach ($request->aggregates as $item)
		{
			$selectParts = array_merge($selectParts, $this->buildAggregateSelect($item));
		}

		$sql = "SELECT\n  " . implode("\n  ,", $selectParts)
			. "\nFROM\n  " . $this->schema->getTableName() . ' AS ' . $this->schema->getTableAlias()
		;

		if ($joins !== '')
		{
			$sql .= "\n  " . $joins;
		}

		if ($whereClause !== '')
		{
			$sql .= "\nWHERE " . $whereClause;
		}

		if ($groupParts !== [])
		{
			$sql .= "\nGROUP BY\n  " . implode("\n  ,", $groupParts);
		}

		if ($request->having !== null)
		{
			$sql .= "\nHAVING " . $this->buildHaving($request->having);
		}

		$orderBy = $this->buildOrderBy($request);
		if ($orderBy !== '')
		{
			$sql .= "\nORDER BY " . $orderBy;
		}

		if ($request->groupLimit !== null)
		{
			$sql .= "\n" . $this->dialect->getGroupLimitClause($request->groupLimit);
		}

		return $sql . "\n";
	}

	private function buildGroupExpression(GroupItem $item): string
	{
		$info = $this->schema->getFieldInfo($item->field);
		$inputType = $this->inputType($item->field);

		if ($item->transform !== null)
		{
			return $this->buildTruncatedExpression($item, $info);
		}

		// An untransformed temporal key reuses the select expression of the raw path verbatim.
		if ($inputType->isTemporal())
		{
			return \CBIConnectorSqlBuilder::GetFieldExpression($info, $this->dateFormats, $this->dialect);
		}

		return $this->identityOperand($item->field, $info, $inputType);
	}

	/**
	 * Timezone conversion, then the transform arithmetic, then formatting - strictly in this order
	 * (invariant И-1): Superset applies its own expressions to the wall clock value Trino already
	 * sees in the portal timezone.
	 */
	private function buildTruncatedExpression(GroupItem $item, array $info): string
	{
		$expression = $this->applyTimezone(
			\CBIConnectorSqlBuilder::GetRawFieldExpression($info, $this->dialect),
			$info,
		);
		$truncated = $this->dialect->truncateDate($item->transform, $expression);

		return $this->formatTemporal($truncated, $item->outputType);
	}

	/**
	 * @return string[] One entry, or two when an AVG expands into its sum/count pair.
	 */
	private function buildAggregateSelect(AggregateItem $item): array
	{
		if ($item->isAverage())
		{
			return $this->buildAverageSelect($item);
		}

		$expression = $this->buildAggregateExpression($item);
		$this->expressionByAlias[$item->alias] = $expression;
		$this->typeByAlias[$item->alias] = $item->outputType;

		return [$expression . ' AS ' . $this->dialect->quoteIdentifier($item->alias)];
	}

	private function buildAggregateExpression(AggregateItem $item): string
	{
		if ($item->field === null)
		{
			return $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
				AggregateExpressionSpec::COUNT_STAR,
				false,
				null,
				$item->outputType,
				$item->wireType ?? $item->outputType,
				null,
			));
		}

		$info = $this->schema->getFieldInfo($item->field);
		$inputType = $this->inputType($item->field);

		return match ($item->function)
		{
			AggregateItem::COUNT => $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
				AggregateExpressionSpec::COUNT,
				$item->distinct,
				$inputType,
				$item->outputType,
				$item->wireType ?? $item->outputType,
				$item->distinct
					? $this->identityOperand($item->field, $info, $inputType)
					: \CBIConnectorSqlBuilder::GetRawFieldExpression($info, $this->dialect),
			)),
			AggregateItem::SUM => $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
				AggregateExpressionSpec::SUM,
				$item->distinct,
				$inputType,
				$item->outputType,
				$item->wireType ?? $item->outputType,
				$this->sumOperand($item, $info, $inputType),
			)),
			AggregateItem::MIN, AggregateItem::MAX => $this->buildExtremum($item, $info, $inputType),
			default => throw AggregationException::badRequest("cannot build {$item->function}"),
		};
	}

	private function buildExtremum(AggregateItem $item, array $info, TypeSpec $inputType): string
	{
		$function = $item->function === AggregateItem::MIN
			? AggregateExpressionSpec::MIN
			: AggregateExpressionSpec::MAX
		;
		$raw = \CBIConnectorSqlBuilder::GetRawFieldExpression($info, $this->dialect);

		if ($inputType->isDouble())
		{
			return $this->dialect->getNanSkippingMinMax($function, $raw);
		}

		if ($inputType->isTemporal())
		{
			// MIN/MAX commute with a monotone timezone conversion, so both the conversion and the
			// formatting stay outside the aggregate.
			$extremum = $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
				$function,
				false,
				$inputType,
				$item->outputType,
				$item->wireType ?? $item->outputType,
				$raw,
			));

			return $this->formatTemporal($this->applyTimezone($extremum, $info), $inputType);
		}

		return $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
			$function,
			false,
			$inputType,
			$item->outputType,
			$item->wireType ?? $item->outputType,
			$this->identityOperand($item->field, $info, $inputType),
		));
	}

	/**
	 * AVG travels as the sum/count pair of design §3.2: the type of the sum follows the input type,
	 * because Trino accumulates AVG(bigint) in double and AVG(decimal) exactly.
	 *
	 * @return string[]
	 */
	private function buildAverageSelect(AggregateItem $item): array
	{
		$info = $this->schema->getFieldInfo($item->field);
		$inputType = $this->inputType($item->field);

		$sumWireType = TypeMatrix::averageSumWireType($inputType);
		if ($sumWireType === null)
		{
			throw AggregationException::typeMismatch("AVG over {$inputType} is outside the matrix");
		}

		$operand = $this->averageOperand($item, $info, $inputType);
		$sumOperand = $inputType->base === TypeSpec::BIGINT
			? $this->dialect->castToDouble($operand)
			: $operand
		;

		$sumExpression = $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
			AggregateExpressionSpec::SUM,
			$item->distinct,
			// The sum of the BIGINT pair is accumulated in double, so the dialect must not widen it
			// into an exact integer sum.
			$inputType->base === TypeSpec::BIGINT ? TypeSpec::simple(TypeSpec::DOUBLE) : $inputType,
			$sumWireType,
			$sumWireType,
			$sumOperand,
		));

		$countExpression = $this->dialect->getAggregateExpression(new AggregateExpressionSpec(
			AggregateExpressionSpec::COUNT,
			$item->distinct,
			$inputType,
			TypeSpec::simple(TypeSpec::BIGINT),
			TypeSpec::simple(TypeSpec::BIGINT),
			$operand,
		));

		$this->expressionByAlias[$item->alias] = $this->dialect->getTrinoAverageExpression(
			$sumExpression,
			$countExpression,
			$inputType,
		);
		$this->typeByAlias[$item->alias] = $item->outputType;

		return [
			$sumExpression . ' AS ' . $this->dialect->quoteIdentifier($item->getSumAlias()),
			$countExpression . ' AS ' . $this->dialect->quoteIdentifier($item->getCountAlias()),
		];
	}

	private function averageOperand(AggregateItem $item, array $info, TypeSpec $inputType): string
	{
		$raw = \CBIConnectorSqlBuilder::GetRawFieldExpression($info, $this->dialect);

		// DISTINCT over double deduplicates by the canonical value, as Trino does.
		if ($item->distinct && $inputType->isDouble())
		{
			return $this->dialect->getCanonicalDoubleGroupKey($raw);
		}

		return $raw;
	}

	private function sumOperand(AggregateItem $item, array $info, TypeSpec $inputType): string
	{
		return $this->averageOperand($item, $info, $inputType);
	}

	/**
	 * The expression that decides value identity: grouping, DISTINCT and extremes must all see the
	 * same normalization the engine applies.
	 */
	private function identityOperand(string $field, array $info, TypeSpec $inputType): string
	{
		$raw = \CBIConnectorSqlBuilder::GetRawFieldExpression($info, $this->dialect);

		if ($inputType->isTextual())
		{
			return $this->dialect->getBinaryStringExpression($raw);
		}

		if ($inputType->isDouble())
		{
			return $this->dialect->getCanonicalDoubleGroupKey($raw);
		}

		if ($inputType->base === TypeSpec::BOOLEAN)
		{
			$expression = $this->dialect->getBooleanWireExpression(
				$raw,
				$this->schema->getFieldNativeSqlType($field),
			);
			if ($expression === null)
			{
				throw AggregationException::unsupportedField("{$field}: boolean carrier is not normalizable");
			}

			return $expression;
		}

		return $raw;
	}

	private function buildHaving(HavingNode $node): string
	{
		return match ($node->op)
		{
			HavingNode::OP_AND => '(' . implode(' AND ', $this->buildChildren($node)) . ')',
			HavingNode::OP_OR => '(' . implode(' OR ', $this->buildChildren($node)) . ')',
			HavingNode::OP_NOT => 'NOT (' . $this->buildHaving($node->arg) . ')',
			HavingNode::OP_ISNULL => '(' . $this->aliasExpression($node->ref) . ' IS NULL)',
			HavingNode::OP_IN => $this->buildIn($node),
			HavingNode::OP_BETWEEN => $this->buildBetween($node),
			default => $this->buildComparison($node->op, $node->ref, $node->value),
		};
	}

	/**
	 * @return string[]
	 */
	private function buildChildren(HavingNode $node): array
	{
		$parts = [];
		foreach ($node->args as $child)
		{
			$parts[] = $this->buildHaving($child);
		}

		return $parts;
	}

	private function buildIn(HavingNode $node): string
	{
		$type = $this->aliasType($node->ref);

		// Double comparison is assembled out of the IEEE primitives instead of a native IN, whose
		// NaN semantics differs from Trino on PostgreSQL.
		if ($type->isDouble())
		{
			$parts = [];
			foreach ($node->values as $literal)
			{
				$parts[] = $this->buildComparison(HavingNode::OP_EQ, $node->ref, $literal);
			}

			return '(' . implode(' OR ', $parts) . ')';
		}

		$values = [];
		foreach ($node->values as $literal)
		{
			$values[] = $this->literal($literal);
		}

		return '(' . $this->aliasExpression($node->ref) . ' IN (' . implode(', ', $values) . '))';
	}

	private function buildBetween(HavingNode $node): string
	{
		$type = $this->aliasType($node->ref);

		if ($type->isDouble())
		{
			return '('
				. $this->buildComparison(HavingNode::OP_GE, $node->ref, $node->low)
				. ' AND '
				. $this->buildComparison(HavingNode::OP_LE, $node->ref, $node->high)
				. ')'
			;
		}

		return '(' . $this->aliasExpression($node->ref)
			. ' BETWEEN ' . $this->literal($node->low)
			. ' AND ' . $this->literal($node->high) . ')'
		;
	}

	private function buildComparison(string $operator, string $ref, Literal $literal): string
	{
		$expression = $this->aliasExpression($ref);
		$type = $this->aliasType($ref);

		if ($type->isDouble())
		{
			return $this->dialect->getIeeeDoubleComparison($operator, $expression, $this->literal($literal));
		}

		$sqlOperator = match ($operator)
		{
			HavingNode::OP_EQ => '=',
			HavingNode::OP_NEQ => '<>',
			HavingNode::OP_LT => '<',
			HavingNode::OP_LE => '<=',
			HavingNode::OP_GT => '>',
			HavingNode::OP_GE => '>=',
			default => throw AggregationException::badRequest("unknown comparison {$operator}"),
		};

		return '(' . $expression . ' ' . $sqlOperator . ' ' . $this->literal($literal) . ')';
	}

	/**
	 * The literal goes through the same expression as the reference, so both sides of a comparison
	 * are normalized identically.
	 */
	private function literal(Literal $literal): string
	{
		$type = $literal->type;

		if ($type->isDouble())
		{
			return $this->dialect->castToDouble($literal->value);
		}

		if ($type->base === TypeSpec::BIGINT || $type->isDecimal())
		{
			return $literal->value;
		}

		$quoted = "'" . $this->dialect->escapeString($literal->value) . "'";

		return $type->isTextual() ? $this->dialect->getBinaryStringExpression($quoted) : $quoted;
	}

	private function buildOrderBy(Request $request): string
	{
		$keys = [];
		foreach ($request->orderBy as $item)
		{
			$keys = array_merge($keys, $this->dialect->getOrderByExpressions(
				$this->aliasExpression($item->target),
				$this->aliasType($item->target),
				$item->ascending,
				$item->nullsFirst,
			));
		}

		return implode(', ', $keys);
	}

	private function aliasExpression(string $alias): string
	{
		// HAVING and ORDER BY repeat the expression instead of the alias: PostgreSQL does not accept
		// a select alias in HAVING.
		$expression = $this->expressionByAlias[$alias] ?? null;
		if ($expression === null)
		{
			throw AggregationException::badRequest("alias {$alias} is not declared in the block");
		}

		return $expression;
	}

	private function aliasType(string $alias): TypeSpec
	{
		$type = $this->typeByAlias[$alias] ?? null;
		if ($type === null)
		{
			throw AggregationException::badRequest("alias {$alias} is not declared in the block");
		}

		return $type;
	}

	/**
	 * Every temporal branch of the aggregated select asks the same question as the raw select
	 * (CBIConnectorSqlBuilder::AppliesTimezoneConversion) and the same question the date filter asks, so a
	 * delegated aggregate cannot shift a field the filter over that field leaves alone. The field
	 * description, not the wire type, decides it: only that description is available to the filter too.
	 */
	private function applyTimezone(string $expression, array $info): string
	{
		if (!\CBIConnectorSqlBuilder::AppliesTimezoneConversion($info, $this->dateFormats))
		{
			return $expression;
		}

		$timezone = DataTimezone::getTimezoneOffset();
		if (!$timezone)
		{
			return $expression;
		}

		return $this->dialect->convertTimezone(
			$expression,
			$this->dialect->getSessionTimezoneExpression(),
			$timezone,
		);
	}

	/**
	 * Formatting follows the same rule as the raw select: with no format configured for this type the
	 * native temporal value travels as is, exactly as an unaggregated select of the same field would.
	 */
	private function formatTemporal(string $expression, TypeSpec $type): string
	{
		$key = $type->base === TypeSpec::DATE ? 'date_format' : 'datetime_format';
		if (!isset($this->dateFormats[$key]))
		{
			return $expression;
		}

		return $this->dialect->formatDateExpression((string)$this->dateFormats[$key], $expression);
	}

	private function inputType(string $field): TypeSpec
	{
		$type = TypeMatrix::inputType($this->schema->getFieldWireType($field));
		if ($type === null)
		{
			throw AggregationException::unsupportedField($field);
		}

		return $type;
	}
}
