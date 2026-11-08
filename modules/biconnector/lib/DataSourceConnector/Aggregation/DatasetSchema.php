<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Read-only view of a dataset for the aggregation layer.
 *
 * Two descriptions of the same dataset have to be consulted together: the raw definition, which
 * carries the SQL side (FIELD_NAME, joins, CALLBACK, UNIONS, GROUP), and the published schema,
 * which carries the wire type and the PHP post-aggregation markers the plugin also sees.
 */
final class DatasetSchema
{
	public const ROW_SET_RAW = 'RAW';
	public const ROW_SET_PHP_AGGREGATED = 'PHP_AGGREGATED';
	public const ROW_SET_UNIONED = 'UNIONED';
	public const ROW_SET_PRE_GROUPED = 'PRE_GROUPED';

	/** Wire types that take no part in aggregation in any role. */
	private const NON_AGGREGATABLE_TYPES = ['ARRAY_STRING', 'MAP_STRING'];

	/** @var array<string, array> */
	private array $schemaByName = [];

	/**
	 * @param array $rawInfo Dataset definition (TABLE_NAME, TABLE_ALIAS, FIELDS, UNIONS, GROUP, ...).
	 * @param array $schemaFields List of FieldDto::toArray() entries, in publication order.
	 */
	public function __construct(
		private readonly array $rawInfo,
		private readonly array $schemaFields,
	)
	{
		foreach ($schemaFields as $field)
		{
			if (isset($field['ID']))
			{
				$this->schemaByName[(string)$field['ID']] = $field;
			}
		}
	}

	public function getTableName(): string
	{
		return (string)($this->rawInfo['TABLE_NAME'] ?? '');
	}

	public function getTableAlias(): string
	{
		return (string)($this->rawInfo['TABLE_ALIAS'] ?? '');
	}

	public function getRawFields(): array
	{
		return is_array($this->rawInfo['FIELDS'] ?? null) ? $this->rawInfo['FIELDS'] : [];
	}

	public function getSchemaFields(): array
	{
		return $this->schemaFields;
	}

	public function hasField(string $name): bool
	{
		return isset($this->getRawFields()[$name]);
	}

	public function getFieldInfo(string $name): array
	{
		$fields = $this->getRawFields();
		if (!isset($fields[$name]) || !is_array($fields[$name]))
		{
			throw AggregationException::unsupportedField($name);
		}

		return $fields[$name];
	}

	/**
	 * Wire type of a column as published by `?desc` (INT, DOUBLE, DATETIME, STRING, ...).
	 */
	public function getFieldWireType(string $name): string
	{
		return (string)($this->schemaByName[$name]['TYPE'] ?? '');
	}

	/**
	 * SQL type of the column on the source, when the dataset declares one.
	 *
	 * Declared through DatasetField::setNativeSqlType(). A `bool` field that declares no carrier has
	 * no truth table anywhere in the data channel - neither FieldDto nor CSQLWhere defines one - so no
	 * normalizing expression can be derived and the field is excluded rather than guessed.
	 */
	public function getFieldNativeSqlType(string $name): string
	{
		$info = $this->getRawFields()[$name] ?? [];

		return (string)($info['NATIVE_SQL_TYPE'] ?? '');
	}

	public function isNonAggregatableType(string $name): bool
	{
		return in_array($this->getFieldWireType($name), self::NON_AGGREGATABLE_TYPES, true);
	}

	/**
	 * Collation of the column when the dataset declares one. An empty answer means "unknown", which the
	 * WHERE precision rules treat as unproven rather than as the default collation.
	 */
	public function getFieldCollation(string $name): string
	{
		$info = $this->getRawFields()[$name] ?? [];

		return (string)($info['COLLATION'] ?? '');
	}

	/**
	 * Whether the SQL of the field is an expression rather than a plain column reference.
	 *
	 * Decided syntactically, because no dataset declares it: an expression may change the string it
	 * returns - trimming, concatenation, a function call - and then the comparison the portal applies
	 * is not the comparison Trino would re-check on the returned value. Anything that is not a bare,
	 * optionally qualified and optionally quoted identifier counts as an expression.
	 */
	public function isFieldExpression(string $name): bool
	{
		// A field with no raw definition has no provable SQL of its own, so it counts as an expression.
		$fieldName = trim((string)($this->getRawFields()[$name]['FIELD_NAME'] ?? ''));
		if ($fieldName === '')
		{
			return true;
		}

		$identifier = '[`"\[]?[A-Za-z_][A-Za-z0-9_$]*[`"\]]?';

		return preg_match('/^' . $identifier . '(\.' . $identifier . ')?$/', $fieldName) !== 1;
	}

	/**
	 * Columns published as strings.
	 *
	 * Driven by the published schema and not by the raw definition, because the published type is the
	 * one the client sees and classifies a predicate by. A column published as a string but missing
	 * from the raw definition then still reaches the precision rules, where the absence of physical
	 * metadata makes it unproven - rather than dropping out of the list and passing as exact.
	 *
	 * @return string[]
	 */
	public function getStringFieldNames(): array
	{
		$names = [];
		foreach ($this->schemaByName as $name => $field)
		{
			if ((string)($field['TYPE'] ?? '') === WhereExactness::TYPE_STRING)
			{
				$names[] = (string)$name;
			}
		}

		return $names;
	}

	public function hasCallback(string $name): bool
	{
		return isset($this->getRawFields()[$name]['CALLBACK']);
	}

	/**
	 * Row set granularity of the dataset (design §2.1).
	 *
	 * Anything but RAW breaks the row set equivalence principle: an aggregate over multiplied rows
	 * is wrong by a factor, and the plugin has no way to correct it.
	 */
	public function getRowSetSemantics(): string
	{
		if ($this->hasPhpAggregation())
		{
			return self::ROW_SET_PHP_AGGREGATED;
		}

		if (!empty($this->rawInfo['UNIONS']))
		{
			return self::ROW_SET_UNIONED;
		}

		if (!empty($this->rawInfo['GROUP']))
		{
			return self::ROW_SET_PRE_GROUPED;
		}

		return self::ROW_SET_RAW;
	}

	public function hasPhpAggregation(): bool
	{
		foreach ($this->schemaFields as $field)
		{
			if (!empty($field['GROUP_CONCAT']) || !empty($field['GROUP_COUNT']))
			{
				return true;
			}
		}

		return false;
	}

	public function getFilter(): array
	{
		return is_array($this->rawInfo['FILTER'] ?? null) ? $this->rawInfo['FILTER'] : [];
	}

	public function getFilterFields(): array
	{
		return is_array($this->rawInfo['FILTER_FIELDS'] ?? null) ? $this->rawInfo['FILTER_FIELDS'] : [];
	}
}
