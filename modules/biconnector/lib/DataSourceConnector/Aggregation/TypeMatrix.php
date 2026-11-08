<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * The "aggregate x input type -> output type" matrix of design §3.
 *
 * Both sides own a copy of this matrix: the plugin decides whether to delegate by it, the portal
 * independently rejects anything that does not match. The portal never assumes the plugin has
 * already checked.
 */
final class TypeMatrix
{
	/** Portal field type as published by `?desc` -> input type of the plugin. */
	private const FIELD_TYPES = [
		'INT' => TypeSpec::BIGINT,
		'DOUBLE' => TypeSpec::DOUBLE,
		// NUMBER is the legacy spelling the plugin still maps onto DOUBLE.
		'NUMBER' => TypeSpec::DOUBLE,
		'DECIMAL' => TypeSpec::DECIMAL,
		'DATE' => TypeSpec::DATE,
		'DATETIME' => TypeSpec::TIMESTAMP,
		'BOOLEAN' => TypeSpec::BOOLEAN,
		'STRING' => TypeSpec::VARCHAR,
	];

	private function __construct()
	{
	}

	/**
	 * Input type of a column, or null when the column takes no part in aggregation
	 * (ARRAY_STRING and MAP_STRING are not comparable in the protocol).
	 */
	public static function inputType(string $fieldType): ?TypeSpec
	{
		$base = self::FIELD_TYPES[$fieldType] ?? null;

		return match ($base)
		{
			null => null,
			TypeSpec::DECIMAL => TypeSpec::decimal(19, 4),
			TypeSpec::TIMESTAMP => TypeSpec::timestamp(0),
			default => TypeSpec::simple($base),
		};
	}

	/**
	 * Output type of an untransformed grouping key (design §3.6).
	 */
	public static function groupKeyOutputType(TypeSpec $input): ?TypeSpec
	{
		return match ($input->base)
		{
			TypeSpec::BIGINT, TypeSpec::DOUBLE, TypeSpec::VARCHAR, TypeSpec::DATE, TypeSpec::BOOLEAN,
			TypeSpec::DECIMAL, TypeSpec::TIMESTAMP => $input,
			default => null,
		};
	}

	/**
	 * Result and wire types of an aggregate, or null when the cell is outside the matrix.
	 *
	 * A null `wireType` in the answer means the value travels as the sum/count pair of design §3.2.
	 *
	 * @return array{outputType: TypeSpec, wireType: ?TypeSpec}|null
	 */
	public static function aggregateTypes(string $function, bool $distinct, ?TypeSpec $input): ?array
	{
		if ($function === AggregateItem::COUNT)
		{
			return self::countTypes($distinct, $input);
		}

		if ($input === null)
		{
			return null;
		}

		return match ($function)
		{
			AggregateItem::SUM => self::sumTypes($input),
			AggregateItem::AVG => self::averageTypes($distinct, $input),
			AggregateItem::MIN, AggregateItem::MAX => self::extremumTypes($distinct, $input),
			default => null,
		};
	}

	/**
	 * Wire type of the sum half of the AVG pair (design §3.2). The count half is always BIGINT.
	 */
	public static function averageSumWireType(TypeSpec $input): ?TypeSpec
	{
		return match ($input->base)
		{
			// Trino widens every row to double before adding, so an exact integer sum would differ.
			TypeSpec::BIGINT, TypeSpec::DOUBLE => TypeSpec::simple(TypeSpec::DOUBLE),
			TypeSpec::DECIMAL => TypeSpec::decimal(38, 4),
			default => null,
		};
	}

	/**
	 * Semantic capability a cell depends on: `binaryStringOperations`, `floatNanOrderingCompatible`
	 * or none. Ordering of a cell is gated separately, see requiresNanOrdering().
	 */
	public static function requiresBinaryStrings(TypeSpec $type): bool
	{
		return $type->isTextual();
	}

	public static function requiresNanOrdering(TypeSpec $type): bool
	{
		return $type->isDouble();
	}

	private static function countTypes(bool $distinct, ?TypeSpec $input): ?array
	{
		$bigint = TypeSpec::simple(TypeSpec::BIGINT);

		// count(*) and count(col) never depend on collation or representation.
		if (!$distinct)
		{
			return ['outputType' => $bigint, 'wireType' => $bigint];
		}

		if ($input === null)
		{
			return null;
		}

		return ['outputType' => $bigint, 'wireType' => $bigint];
	}

	private static function sumTypes(TypeSpec $input): ?array
	{
		return match ($input->base)
		{
			// Trino keeps SUM(bigint) in int64 and fails on overflow; the exact wide total travels
			// as a decimal string and the plugin range-checks it.
			TypeSpec::BIGINT => [
				'outputType' => TypeSpec::simple(TypeSpec::BIGINT),
				'wireType' => TypeSpec::decimal(38, 0),
			],
			TypeSpec::DECIMAL => [
				'outputType' => TypeSpec::decimal(38, 4),
				'wireType' => TypeSpec::decimal(38, 4),
			],
			TypeSpec::DOUBLE => [
				'outputType' => TypeSpec::simple(TypeSpec::DOUBLE),
				'wireType' => TypeSpec::simple(TypeSpec::DOUBLE),
			],
			default => null,
		};
	}

	private static function averageTypes(bool $distinct, TypeSpec $input): ?array
	{
		// AVG(DISTINCT bigint) is not delegated: deduplicating in long space and only then widening
		// to double is not expressible as one aggregate of the source above 2^53 (design §7 Г-5).
		if ($distinct && $input->base === TypeSpec::BIGINT)
		{
			return null;
		}

		$outputType = match ($input->base)
		{
			TypeSpec::BIGINT, TypeSpec::DOUBLE => TypeSpec::simple(TypeSpec::DOUBLE),
			TypeSpec::DECIMAL => TypeSpec::decimal(19, 4),
			default => null,
		};

		return $outputType === null ? null : ['outputType' => $outputType, 'wireType' => null];
	}

	private static function extremumTypes(bool $distinct, TypeSpec $input): ?array
	{
		// The plugin normalizes MIN/MAX DISTINCT away: the extremum of a multiset equals the
		// extremum of the set.
		if ($distinct)
		{
			return null;
		}

		return match ($input->base)
		{
			TypeSpec::BIGINT, TypeSpec::DECIMAL, TypeSpec::DATE, TypeSpec::TIMESTAMP,
			TypeSpec::VARCHAR, TypeSpec::DOUBLE, TypeSpec::BOOLEAN =>
				['outputType' => $input, 'wireType' => $input],
			default => null,
		};
	}
}
