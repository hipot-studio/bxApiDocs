<?php

namespace Bitrix\BIConnector\DB;

use Bitrix\BIConnector\DataSourceConnector\Aggregation\AggregateExpressionSpec;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\DatasetSchema;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\DateTruncSpec;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\DialectSemantics;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\TypeSpec;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\WhereExactness;
use Bitrix\Main\ORM;

/**
 * SQL dialect of ClickHouse for the read-only BI connection.
 *
 * Identifier quoting and the LIMIT clause match MySQL, so the MySQL helper is a suitable base.
 * Everything that differs is overridden here: value escaping (the base helper escapes through a
 * mysqli resource, which this connection does not have), date and string functions, aggregation of
 * values within a group, and the mapping of ClickHouse column types onto ORM fields.
 */
class ClickhouseSqlHelper extends \Bitrix\Main\DB\MysqliSqlHelper implements BiAggregationDialectInterface
{
	private const NATIVE_BOOLEAN_TYPES = ['bool', 'boolean', 'uint8'];

	/** The two spellings of the minute in formatDateTime, see getMinutePlaceholder(). */
	private const MINUTE_PLACEHOLDER_LEGACY = '%M';
	private const MINUTE_PLACEHOLDER_MODERN = '%i';

	/**
	 * Probe that tells the two placeholder generations apart.
	 *
	 * `%M` is accepted by both generations, which is what makes it usable as a probe: an older server
	 * renders the minute and a newer one the full month name, so the answer is unambiguous and neither
	 * branch depends on catching an error.
	 */
	private const MINUTE_PROBE_QUERY =
		"SELECT formatDateTime(toDateTime('2000-01-01 00:02:00'), '" . self::MINUTE_PLACEHOLDER_LEGACY . "')";

	/** What the probe returns while `%M` still means the minute. */
	private const MINUTE_PROBE_LEGACY_RESULT = '02';

	private const BYTE_WISE_STRING_TYPES = ['string'];

	private const COMPARISON_OPERATORS = [
		'eq' => '=',
		'neq' => '<>',
		'lt' => '<',
		'le' => '<=',
		'gt' => '>',
		'ge' => '>=',
	];

	private ?string $minutePlaceholder = null;

	/**
	 * Escapes a value for a single-quoted SQL literal.
	 *
	 * ClickHouse follows the C-style escaping rules for string literals, so a backslash and a single
	 * quote are the characters that must be escaped. Control characters are escaped as well to keep
	 * a value from breaking the statement apart.
	 *
	 * @param string $value
	 * @param int $maxLength
	 *
	 * @return string
	 */
	public function forSql($value, $maxLength = 0)
	{
		$value = (string)$value;

		if ($maxLength > 0)
		{
			$value = mb_substr($value, 0, $maxLength);
		}

		return str_replace(
			['\\', "'", "\n", "\r", "\t", "\0"],
			['\\\\', "\\'", '\\n', '\\r', '\\t', '\\0'],
			$value
		);
	}

	public function getCurrentDateTimeFunction()
	{
		return 'now()';
	}

	public function getCurrentDateFunction()
	{
		return 'today()';
	}

	public function addSecondsToDateTime($seconds, $from = null)
	{
		if ($from === null)
		{
			$from = $this->getCurrentDateTimeFunction();
		}

		return "({$from} + INTERVAL " . (int)$seconds . ' SECOND)';
	}

	public function getDatetimeToDateFunction($value)
	{
		return "toDate({$value})";
	}

	/**
	 * Converts a Bitrix date format into a ClickHouse one.
	 *
	 * The placeholders are deliberately not reused from the MySQL helper: the two dialects disagree
	 * on several of them, and the month name is `%B` here.
	 *
	 * The minute placeholder depends on the server version, see getMinutePlaceholder().
	 *
	 * The month name placeholder appeared in ClickHouse 23.4, so a format that asks for it fails on
	 * older servers. The BI read path never asks for it: the formats it uses are 'YYYY-MM-DD' and
	 * 'YYYY-MM-DD HH:MI:SS'.
	 *
	 * @param string $format Bitrix format, e.g. 'YYYY-MM-DD HH:MI:SS'.
	 * @param string|null $field Field or expression; when null only the converted format is returned.
	 *
	 * @return string
	 */
	public function formatDate($format, $field = null)
	{
		static $search = [
			'YYYY',
			'MMMM',
			'MM',
			'MI',
			'DD',
			'HH',
			'GG',
			'G',
			'SS',
			'TT',
			'T',
			'W',
		];

		$replace = [
			'%Y',
			'%B',
			'%m',
			$this->getMinutePlaceholder(),
			'%d',
			'%H',
			'%I',
			'%I',
			'%S',
			'%p',
			'%p',
			'%w',
		];

		$format = str_replace($search, $replace, $format);

		if ($field === null)
		{
			return $format;
		}

		return "formatDateTime({$field}, '" . $this->forSql($format) . "')";
	}

	/**
	 * The minute placeholder of the connected server, resolved once per helper.
	 *
	 * ClickHouse swapped the meaning of these placeholders. Measured: on 22.1.3.7 `%M` is the minute
	 * and `%i` is rejected outright ("Wrong pattern '%i'"), while on 23.8.16.16 and 26.7.1.1315 `%M`
	 * renders the full month name and `%i` is the minute. Hard-coding either spelling would silently
	 * corrupt every datetime of a BI dataset on the other side of the change, so the server is asked.
	 *
	 * The support is established by behaviour rather than by comparing version numbers: the exact
	 * release of the change was not confirmed here, and a number would in any case be unreliable
	 * because the placeholder set is backported into patch releases - 23.8.16.16 already carries it
	 * even though the feature is younger than 23.8. The probe cannot be wrong about the server in
	 * front of it, and it costs one cached query.
	 *
	 * Without a reachable server the modern spelling is assumed: a helper built without a connection
	 * only ever converts a format string for inspection, never for execution.
	 */
	private function getMinutePlaceholder(): string
	{
		if ($this->minutePlaceholder !== null)
		{
			return $this->minutePlaceholder;
		}

		$this->minutePlaceholder = self::MINUTE_PLACEHOLDER_MODERN;

		if (!isset($this->connection))
		{
			return $this->minutePlaceholder;
		}

		try
		{
			$probe = (string)$this->connection->queryScalar(self::MINUTE_PROBE_QUERY);
		}
		catch (\Throwable)
		{
			// An unreachable server tells us nothing; the assumption above stands.
			return $this->minutePlaceholder;
		}

		if (trim($probe) === self::MINUTE_PROBE_LEGACY_RESULT)
		{
			$this->minutePlaceholder = self::MINUTE_PLACEHOLDER_LEGACY;
		}

		return $this->minutePlaceholder;
	}

	public function getIsNullFunction($expression, $result)
	{
		return "ifNull({$expression}, {$result})";
	}

	public function getLengthFunction($field)
	{
		return "length({$field})";
	}

	public function getCharToDateFunction($value)
	{
		return "parseDateTimeBestEffort('" . $this->forSql($value) . "')";
	}

	public function getDateToCharFunction($fieldName)
	{
		return "formatDateTime({$fieldName}, '%Y-%m-%d %H:%M:%S')";
	}

	public function castToChar($fieldName)
	{
		return "toString({$fieldName})";
	}

	public function softCastTextToChar($fieldName)
	{
		return "toString({$fieldName})";
	}

	/**
	 * @param string $tableName
	 * @param array $primaryFields
	 * @param array $insertFields
	 * @param array $updateFields
	 *
	 * @return array
	 * @throws \Bitrix\Main\NotSupportedException Always: the connection is read-only.
	 */
	public function prepareMerge($tableName, array $primaryFields, array $insertFields, array $updateFields)
	{
		throw new \Bitrix\Main\NotSupportedException('Clickhouse BI connection is read-only.');
	}

	public function getSessionTimezoneExpression(): string
	{
		return 'timezone()';
	}

	/**
	 * Shifts a datetime expression to the given timezone offset.
	 *
	 * ClickHouse keeps the timezone inside the DateTime type and reports the effective offset of a
	 * value through timeZoneOffset(). The value is shifted by the difference between the target offset
	 * and that one, which is the same arithmetic the MySQL and PostgreSQL helpers do.
	 *
	 * @param string $field Column or expression.
	 * @param string $fromTimezone Source timezone expression. Anything other than
	 *                             getSessionTimezoneExpression() must be a constant zone name,
	 *                             because ClickHouse accepts only a literal there.
	 * @param string $toTimezone Target offset, e.g. '+03:00'.
	 *
	 * @return string
	 */
	public function convertTimezone(string $field, string $fromTimezone, string $toTimezone): string
	{
		$targetOffsetInSeconds = $this->convertTimezoneOffsetToSeconds($toTimezone);

		$source = $fromTimezone === $this->getSessionTimezoneExpression()
			? "toDateTime({$field})"
			: sprintf("toDateTime(%s, '%s')", $field, $this->forSql($fromTimezone))
		;

		return sprintf(
			'addSeconds(%s, %d - toInt64(timeZoneOffset(%s)))',
			$source,
			$targetOffsetInSeconds,
			$source
		);
	}

	/**
	 * @param string $expression
	 * @param string $orderBy
	 * @param string $separator
	 *
	 * @return string
	 * @throws \Bitrix\Main\NotSupportedException When an ordering is requested.
	 */
	public function getGroupConcatExpression(string $expression, string $orderBy = '', string $separator = ','): string
	{
		if ($orderBy !== '')
		{
			// groupArray() has no ORDER BY clause, and reproducing it would require sorting an array
			// of tuples by a raw SQL fragment. Failing loudly is better than silently concatenating
			// the values in an arbitrary order.
			throw new \Bitrix\Main\NotSupportedException(
				'Ordered group concatenation is not supported for Clickhouse.'
			);
		}

		$escapedSeparator = $this->forSql($separator);

		return "arrayStringConcat(arrayMap(x -> toString(x), groupArray(({$expression}))), '{$escapedSeparator}')";
	}

	public function getSegmentByDelimiter(string $field, string $delimiter, int $n): string
	{
		if ($n < 1)
		{
			throw new \InvalidArgumentException("Segment index must be >= 1, got {$n}.");
		}

		$escapedDelimiter = $this->forSql($delimiter);

		// Mirrors PostgreSQL split_part: NULL field -> NULL, missing N-th segment -> ''.
		// splitByString() must not receive a Nullable argument: an array inside Nullable is not a
		// valid ClickHouse type, so the value is unwrapped with ifNull() before splitting and the
		// NULL case is restored by the outer if().
		return "if({$field} IS NULL, NULL, arrayElement(splitByString('{$escapedDelimiter}', ifNull(toString({$field}), '')), {$n}))";
	}

	public function getDateDiffSecondsExpression(string $start, string $end): string
	{
		return "toInt32(dateDiff('second', toDateTime({$start}), toDateTime({$end})))";
	}

	public function castToInt(string $expr): string
	{
		return "toInt64OrNull(toString({$expr}))";
	}

	public function castIntToChar(string $expr): string
	{
		return "toString({$expr})";
	}

	public function castNumericToChar(string $expr): string
	{
		return "toString({$expr})";
	}

	/**
	 * @param string $expression
	 * @param string $scaleExpression Must be a constant: ClickHouse rejects a per-row scale.
	 *
	 * @return string
	 */
	public function getRoundExpression(string $expression, string $scaleExpression): string
	{
		return "round(({$expression}), ({$scaleExpression}))";
	}

	public function castNumericToInt(string $expr): string
	{
		return "CAST({$expr} AS Int64)";
	}

	public function quoteIdentifier(string $identifier): string
	{
		return $this->quote($identifier);
	}

	public function escapeString(string $value): string
	{
		return $this->forSql($value);
	}

	public function formatDateExpression(string $format, string $expression): string
	{
		return $this->formatDate($format, $expression);
	}

	/**
	 * toStartOfInterval() is used for the bucketed units instead of toStartOfSecond(): the latter
	 * accepts DateTime64 only, while the BI read path also carries plain DateTime. For buckets that
	 * divide the parent unit (60/60/24) the interval boundaries coincide with the modulo formula of
	 * the Superset expression.
	 */
	public function truncateDate(DateTruncSpec $spec, string $expression): string
	{
		$base = $spec->preOffsetDays === 0
			? $expression
			: sprintf('addDays(%s, %d)', $expression, $spec->preOffsetDays)
		;

		$truncated = match ($spec->unit)
		{
			DateTruncSpec::UNIT_SECOND => sprintf('toStartOfInterval(%s, INTERVAL %d SECOND)', $base, $spec->bucket),
			DateTruncSpec::UNIT_MINUTE => $spec->hasBucket()
				? sprintf('toStartOfInterval(%s, INTERVAL %d MINUTE)', $base, $spec->bucket)
				: "toStartOfMinute({$base})",
			DateTruncSpec::UNIT_HOUR => $spec->hasBucket()
				? sprintf('toStartOfInterval(%s, INTERVAL %d HOUR)', $base, $spec->bucket)
				: "toStartOfHour({$base})",
			DateTruncSpec::UNIT_DAY => "toStartOfDay({$base})",
			DateTruncSpec::UNIT_WEEK => "toMonday({$base})",
			DateTruncSpec::UNIT_MONTH => "toStartOfMonth({$base})",
			DateTruncSpec::UNIT_QUARTER => "toStartOfQuarter({$base})",
			DateTruncSpec::UNIT_YEAR => "toStartOfYear({$base})",
		};

		if ($spec->postOffsetDays !== 0)
		{
			return sprintf('addDays(%s, %d)', $truncated, $spec->postOffsetDays);
		}

		return $truncated;
	}

	/**
	 * The -OrNull combinator is mandatory: a bare sum() over an empty input returns 0 in ClickHouse,
	 * while Trino returns NULL. count(DISTINCT) and countDistinct() are never generated - the alias
	 * they resolve to is chosen by the server setting count_distinct_implementation and may be
	 * approximate; uniqExact() is exact by contract.
	 */
	public function getAggregateExpression(AggregateExpressionSpec $spec): string
	{
		$expression = (string)$spec->expr;

		return match ($spec->function)
		{
			AggregateExpressionSpec::COUNT_STAR => 'count()',
			AggregateExpressionSpec::COUNT => $spec->distinct
				? "uniqExact({$expression})"
				: "count({$expression})",
			AggregateExpressionSpec::SUM => $this->getSumExpression($spec, $expression),
			AggregateExpressionSpec::MIN => "minOrNull({$expression})",
			AggregateExpressionSpec::MAX => "maxOrNull({$expression})",
		};
	}

	public function castToDouble(string $expression): string
	{
		return "toFloat64({$expression})";
	}

	public function getTrinoAverageExpression(
		string $sumExpression,
		string $countExpression,
		TypeSpec $inputType,
	): string
	{
		$safeCount = "(CASE WHEN {$countExpression} = 0 THEN 1 ELSE {$countExpression} END)";

		if (!$inputType->isDecimal())
		{
			return "CASE WHEN {$countExpression} = 0 THEN NULL ELSE ({$sumExpression} / {$safeCount}) END";
		}

		$scale = $inputType->getScaleOrZero();
		$factor = 10 ** $scale;
		$unscaled = "toInt256(toDecimal256(abs({$sumExpression}), {$scale}) * {$factor})";
		$divisor = "toInt256({$safeCount})";
		$quotient = "intDiv({$unscaled}, {$divisor})";
		$remainder = "modulo({$unscaled}, {$divisor})";
		$halfUp = "(intDiv({$divisor}, 2) + modulo({$divisor}, 2))";
		$rounded = "({$quotient} + CASE WHEN {$remainder} >= {$halfUp} THEN 1 ELSE 0 END)";
		$signed = "(CASE WHEN {$sumExpression} < 0 THEN -1 ELSE 1 END * {$rounded})";

		return "CASE WHEN {$countExpression} = 0 THEN NULL"
			. " ELSE (toDecimal256({$signed}, {$scale}) / {$factor}) END"
		;
	}

	/**
	 * ClickHouse keeps +0.0 and -0.0 in separate groups, unlike Trino. Comparison with zero is a
	 * reliable marker because -0.0 = 0.0 holds there; NaN grouping already matches Trino.
	 */
	public function getCanonicalDoubleGroupKey(string $expression): string
	{
		return "if({$expression} = 0, 0.0, {$expression})";
	}

	public function getBinaryStringExpression(string $expression): string
	{
		return "({$expression})";
	}

	public function getBooleanWireExpression(string $expression, string $nativeSqlType): ?string
	{
		$baseType = self::getBaseTypeName(self::unwrapType($nativeSqlType));
		if (!in_array($baseType, self::NATIVE_BOOLEAN_TYPES, true))
		{
			return null;
		}

		return "if(isNull({$expression}), NULL, if({$expression} != 0, 'true', 'false'))";
	}

	/**
	 * ClickHouse keeps NaN last regardless of the direction and isNaN(NULL) is NULL, so neither a
	 * bare value key nor a two-level key controls both positions. Three levels do: NULL rank, NaN
	 * rank, value.
	 */
	public function getOrderByExpressions(
		string $expression,
		TypeSpec $type,
		bool $ascending,
		bool $nullsFirst,
	): array
	{
		$direction = $ascending ? 'ASC' : 'DESC';

		if (!$type->isDouble())
		{
			$nulls = $nullsFirst ? 'NULLS FIRST' : 'NULLS LAST';

			return ["{$expression} {$direction} {$nulls}"];
		}

		return [
			sprintf('(%s IS NULL) %s', $expression, $nullsFirst ? 'DESC' : 'ASC'),
			sprintf('isNaN(%s) %s', $expression, self::getNanKeyDirection($ascending, $nullsFirst)),
			"{$expression} {$direction}",
		];
	}

	/**
	 * Direction of the NaN rank key, measured against the engine over [NULL, NaN, 1.0, 5.0, 5.0].
	 *
	 * The rank of NaN there follows the NULLS clause, not the direction: with NULLS FIRST it behaves as
	 * the smallest value and with NULLS LAST as the greatest, and the direction then reverses the result.
	 * NaN therefore leads the non-null rows exactly when the direction and the clause agree - ascending
	 * with NULLS FIRST, descending with NULLS LAST. Keying it to the direction alone reproduces only two
	 * of the four combinations, and a delegated top-N would then cut a different group at the boundary.
	 */
	private static function getNanKeyDirection(bool $ascending, bool $nullsFirst): string
	{
		return $ascending === $nullsFirst ? 'DESC' : 'ASC';
	}

	/**
	 * Native min()/max() skip NaN while another value is present, but over an all-NaN input they
	 * return NaN, whereas Trino skips every value and answers NULL. Verified on the server, so NaN is
	 * mapped to NULL before the aggregate; -OrNull keeps the empty input NULL as well.
	 */
	public function getNanSkippingMinMax(string $function, string $expression): string
	{
		$aggregate = $function === AggregateExpressionSpec::MIN ? 'minOrNull' : 'maxOrNull';

		return "{$aggregate}(if(isNaN({$expression}), NULL, {$expression}))";
	}

	public function getIeeeDoubleComparison(string $operator, string $expression, string $literal): string
	{
		$sqlOperator = self::COMPARISON_OPERATORS[$operator] ?? null;
		if ($sqlOperator === null)
		{
			throw new \InvalidArgumentException("Unsupported comparison operator: {$operator}");
		}

		// Float64 comparison in ClickHouse already follows IEEE.
		return "({$expression} {$sqlOperator} {$literal})";
	}

	public function getGroupLimitClause(int $limit): string
	{
		return 'LIMIT ' . $limit;
	}

	/**
	 * String comparison is binary in ClickHouse and the double comparisons follow IEEE, so every cell of
	 * the raw channel is exact at the dialect level; the per-column proof still applies to strings.
	 */
	public function getExactWhereOperators(): array
	{
		return WhereExactness::buildOperators(
			equalityTypes: array_merge(WhereExactness::EXACT_NUMERIC_TYPES, [WhereExactness::TYPE_STRING]),
			orderedTypes: WhereExactness::EXACT_NUMERIC_TYPES,
		);
	}

	/**
	 * String and LowCardinality(String) compare byte-wise. FixedString pads with zero bytes and is not
	 * proven, and a column whose native type is unknown is not proven either: the absence of metadata is
	 * not a proof of byte semantics.
	 */
	public function getInexactWhereColumns(DatasetSchema $schema): array
	{
		$inexact = [];
		foreach ($schema->getStringFieldNames() as $name)
		{
			if ($schema->isFieldExpression($name))
			{
				$inexact[] = $name;

				continue;
			}

			$nativeType = mb_strtolower(self::getBaseTypeName(self::unwrapType($schema->getFieldNativeSqlType($name))));
			if (!in_array($nativeType, self::BYTE_WISE_STRING_TYPES, true))
			{
				$inexact[] = $name;
			}
		}

		return $inexact;
	}

	public function getSemantics(): DialectSemantics
	{
		return new DialectSemantics(
			binaryStringOperations: true,
			floatNanOrderingCompatible: true,
		);
	}

	public function supportsExactCountDistinct(): bool
	{
		return true;
	}

	private function getSumExpression(AggregateExpressionSpec $spec, string $expression): string
	{
		$argument = $spec->inputType?->base === TypeSpec::BIGINT
			? "toInt128({$expression})"
			: $expression
		;

		return $spec->distinct
			? "sumDistinctOrNull({$argument})"
			: "sumOrNull({$argument})"
		;
	}

	/**
	 * Maps a ClickHouse column type onto an ORM field.
	 *
	 * The type name arrives either from `system.columns` or from the type line of the
	 * JSONCompactEachRowWithNamesAndTypes response, so it may be wrapped into modifiers like
	 * Nullable() or LowCardinality().
	 *
	 * @param string $name Column name.
	 * @param string $type ClickHouse type name.
	 * @param array|null $parameters
	 *
	 * @return ORM\Fields\ScalarField
	 */
	public function getFieldByColumnType($name, $type, ?array $parameters = null)
	{
		$type = self::unwrapType((string)$type);
		$baseType = self::getBaseTypeName($type);

		return match (true)
		{
			$baseType === 'date', $baseType === 'date32' => new ORM\Fields\DateField($name),
			$baseType === 'datetime', $baseType === 'datetime64' => new ORM\Fields\DatetimeField($name),
			$baseType === 'float32', $baseType === 'float64' => new ORM\Fields\FloatField($name),
			str_starts_with($baseType, 'decimal') => new ORM\Fields\FloatField($name),
			$baseType === 'int8', $baseType === 'uint8' => (new ORM\Fields\IntegerField($name))->configureSize(1),
			$baseType === 'int16', $baseType === 'uint16' => (new ORM\Fields\IntegerField($name))->configureSize(2),
			$baseType === 'int32', $baseType === 'uint32' => (new ORM\Fields\IntegerField($name))->configureSize(4),
			$baseType === 'int64' => (new ORM\Fields\IntegerField($name))->configureSize(8),
			// The upper half of UInt64 does not fit a PHP int, and an int cast saturates or rounds such
			// a value silently; the server already sends it as exact decimal text, so a string keeps it.
			// Bool is a string as well: the wire carries 'true'/'false', see getBooleanWireExpression().
			$baseType === 'uint64', $baseType === 'bool', $baseType === 'boolean' => new ORM\Fields\StringField($name),
			default => new ORM\Fields\StringField($name),
		};
	}

	/**
	 * Strips the type modifiers ClickHouse wraps a type into.
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public static function unwrapType(string $type): string
	{
		$type = trim($type);
		$wrappers = ['Nullable', 'LowCardinality', 'SimpleAggregateFunction'];

		$changed = true;
		while ($changed)
		{
			$changed = false;
			foreach ($wrappers as $wrapper)
			{
				$prefix = $wrapper . '(';
				if (stripos($type, $prefix) === 0 && str_ends_with($type, ')'))
				{
					$inner = substr($type, strlen($prefix), -1);

					// SimpleAggregateFunction(max, UInt64): the payload type is the last argument.
					if (strcasecmp($wrapper, 'SimpleAggregateFunction') === 0)
					{
						$inner = self::getLastTopLevelArgument($inner);
					}

					$type = trim($inner);
					$changed = true;
				}
			}
		}

		return $type;
	}

	/**
	 * The argument after the last top-level comma.
	 *
	 * A plain explode would split inside a parameterized payload: for
	 * SimpleAggregateFunction(sum, Decimal(18, 4)) the payload is Decimal(18, 4) and its own comma
	 * does not separate arguments.
	 *
	 * @param string $arguments
	 *
	 * @return string
	 */
	private static function getLastTopLevelArgument(string $arguments): string
	{
		$depth = 0;
		$lastComma = -1;

		for ($i = 0, $length = strlen($arguments); $i < $length; $i++)
		{
			$character = $arguments[$i];
			if ($character === '(')
			{
				$depth++;
			}
			elseif ($character === ')')
			{
				$depth--;
			}
			elseif ($character === ',' && $depth === 0)
			{
				$lastComma = $i;
			}
		}

		return trim($lastComma < 0 ? $arguments : substr($arguments, $lastComma + 1));
	}

	/**
	 * Drops the type parameters, keeping the type family only.
	 *
	 * Decimal(18, 4) and DateTime64(3, 'UTC') carry parameters that do not change the family.
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	private static function getBaseTypeName(string $type): string
	{
		$parenthesis = strpos($type, '(');
		if ($parenthesis !== false)
		{
			$type = substr($type, 0, $parenthesis);
		}

		return mb_strtolower(trim($type));
	}

	private function convertTimezoneOffsetToSeconds(string $timezoneOffset): int
	{
		if (
			!preg_match(
				'/^(?<sign>[+-])(?<hours>\d{2}):(?<minutes>\d{2})$/',
				$timezoneOffset,
				$matches
			)
		)
		{
			throw new \InvalidArgumentException("Unsupported timezone offset: {$timezoneOffset}");
		}

		$seconds = ((int)$matches['hours'] * 3600) + ((int)$matches['minutes'] * 60);

		return $matches['sign'] === '-' ? -$seconds : $seconds;
	}
}
