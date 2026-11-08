<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Vocabulary of the WHERE precision capability (design §5.11.1, §5.11.2).
 *
 * The operators are the ones of the `dimensionsFilters` channel and the types are the logical field
 * types published by `?desc`, so both sides classify a conjunct by the same names.
 */
final class WhereExactness
{
	public const OPERATOR_IS_NULL = 'IS_NULL';
	public const OPERATOR_EQUALS = 'EQUALS';
	public const OPERATOR_IN_LIST = 'IN_LIST';
	public const OPERATOR_BETWEEN = 'BETWEEN';
	public const OPERATOR_GREATER_THAN = 'NUMERIC_GREATER_THAN';
	public const OPERATOR_GREATER_THAN_OR_EQUAL = 'NUMERIC_GREATER_THAN_OR_EQUAL';
	public const OPERATOR_LESS_THAN = 'NUMERIC_LESS_THAN';
	public const OPERATOR_LESS_THAN_OR_EQUAL = 'NUMERIC_LESS_THAN_OR_EQUAL';

	/** Order of the operators in the block; it is part of the capabilities fingerprint. */
	public const OPERATORS = [
		self::OPERATOR_IS_NULL,
		self::OPERATOR_EQUALS,
		self::OPERATOR_IN_LIST,
		self::OPERATOR_BETWEEN,
		self::OPERATOR_GREATER_THAN,
		self::OPERATOR_GREATER_THAN_OR_EQUAL,
		self::OPERATOR_LESS_THAN,
		self::OPERATOR_LESS_THAN_OR_EQUAL,
	];

	/** Ordered comparisons; they share one type list in every dialect. */
	public const ORDERED_OPERATORS = [
		self::OPERATOR_BETWEEN,
		self::OPERATOR_GREATER_THAN,
		self::OPERATOR_GREATER_THAN_OR_EQUAL,
		self::OPERATOR_LESS_THAN,
		self::OPERATOR_LESS_THAN_OR_EQUAL,
	];

	public const TYPE_INT = 'INT';
	public const TYPE_DOUBLE = 'DOUBLE';
	public const TYPE_DECIMAL = 'DECIMAL';
	public const TYPE_DATE = 'DATE';
	public const TYPE_DATETIME = 'DATETIME';
	public const TYPE_STRING = 'STRING';
	public const TYPE_BOOLEAN = 'BOOLEAN';

	/** A NULL test depends on neither collation nor representation, so every type qualifies. */
	public const ALL_TYPES = [
		self::TYPE_INT,
		self::TYPE_DOUBLE,
		self::TYPE_DECIMAL,
		self::TYPE_DATE,
		self::TYPE_DATETIME,
		self::TYPE_STRING,
		self::TYPE_BOOLEAN,
	];

	/** Numeric types whose decimal literals compare identically in SQL and in Trino. */
	public const EXACT_NUMERIC_TYPES = [self::TYPE_INT, self::TYPE_DOUBLE, self::TYPE_DECIMAL];

	private function __construct()
	{
	}

	/**
	 * Builds the operator dictionary out of the two type lists a dialect has to decide on.
	 *
	 * @param string[] $equalityTypes Types whose equality the dialect applies exactly.
	 * @param string[] $orderedTypes Types whose ordered comparisons the dialect applies exactly.
	 *
	 * @return array<string, string[]>
	 */
	public static function buildOperators(array $equalityTypes, array $orderedTypes): array
	{
		$operators = [
			self::OPERATOR_IS_NULL => self::ALL_TYPES,
			self::OPERATOR_EQUALS => $equalityTypes,
			self::OPERATOR_IN_LIST => $equalityTypes,
		];

		foreach (self::ORDERED_OPERATORS as $operator)
		{
			$operators[$operator] = $orderedTypes;
		}

		return $operators;
	}
}
