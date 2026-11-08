<?php

namespace Bitrix\BIConnector\DB;

use Bitrix\BIConnector\DataSourceConnector\Aggregation\AggregateExpressionSpec;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\DatasetSchema;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\DateTruncSpec;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\DialectSemantics;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\TypeSpec;

/**
 * Every SQL operation the shared aggregation layer performs.
 *
 * The shared layer (Capabilities, Validator, SqlBuilder) is typed by this interface only and never
 * touches a concrete SqlHelper. A new data source gains aggregation pushdown by implementing this
 * interface, with no change to the shared layer; the rule is pinned by the fake-dialect contract
 * test.
 *
 * The expected semantics of every method is the one of Trino 478, which is what the plugin
 * substitutes the delegated result for. Where a dialect differs, the method must close the gap in
 * SQL or the corresponding capability must be turned off.
 */
interface BiAggregationDialectInterface extends BiSqlHelperInterface
{
	/**
	 * Quotes an identifier (table, column or alias).
	 */
	public function quoteIdentifier(string $identifier): string;

	/**
	 * Escapes the body of a single-quoted string literal.
	 */
	public function escapeString(string $value): string;

	/**
	 * Formats a datetime expression with a Bitrix format string ('YYYY-MM-DD HH:MI:SS').
	 */
	public function formatDateExpression(string $format, string $expression): string;

	/**
	 * Truncates a datetime expression to the start of the period described by the spec.
	 *
	 * The week starts on ISO Monday. Buckets and week offsets follow the formulas of design §2.2
	 * literally: the offset is applied before truncation, the post offset after it.
	 */
	public function truncateDate(DateTruncSpec $spec, string $expression): string;

	/**
	 * Emits one aggregate column with the NULL semantics of Trino: an empty input and an all-NULL
	 * input both produce NULL for SUM/MIN/MAX and 0 for COUNT.
	 */
	public function getAggregateExpression(AggregateExpressionSpec $spec): string;

	/**
	 * Casts an expression to the double type of the dialect.
	 *
	 * Used for the sum half of AVG(BIGINT), where Trino widens every row to double before adding,
	 * and for double literals of HAVING, so that both sides compare the same bits.
	 */
	public function castToDouble(string $expression): string;

	/**
	 * Rebuilds the average out of the delegated sum/count pair for HAVING and ORDER BY.
	 *
	 * The value must equal the one the plugin computes from the same pair: IEEE division for
	 * double and bigint input, overflow-safe half-up integer arithmetic for decimal input.
	 */
	public function getTrinoAverageExpression(string $sumExpression, string $countExpression, TypeSpec $inputType): string;

	/**
	 * Canonical grouping/DISTINCT key for a double expression, reproducing `DoubleType.identical`:
	 * all NaN are one value, -0.0 and 0.0 are one value.
	 */
	public function getCanonicalDoubleGroupKey(string $expression): string;

	/**
	 * Byte-wise comparison, grouping and extremes for a string expression.
	 */
	public function getBinaryStringExpression(string $expression): string;

	/**
	 * Normalizes a natively boolean column to the wire values 'true'/'false'.
	 *
	 * Returns null when the carrier is not a native boolean and no truth table is available; the
	 * field then goes to excludedColumns.
	 */
	public function getBooleanWireExpression(string $expression, string $nativeSqlType): ?string;

	/**
	 * The complete ordering key for one sort item, as an ordered list of SQL fragments.
	 *
	 * The list fully determines the position of NULL and NaN. For double on a dialect that cannot
	 * express the NaN order natively it holds three levels in a fixed order: NULL rank, NaN rank,
	 * value.
	 *
	 * @return string[]
	 */
	public function getOrderByExpressions(string $expression, TypeSpec $type, bool $ascending, bool $nullsFirst): array;

	/**
	 * MIN/MAX over double that skips NaN, as Trino does.
	 *
	 * @param string $function AggregateExpressionSpec::MIN or AggregateExpressionSpec::MAX.
	 */
	public function getNanSkippingMinMax(string $function, string $expression): string;

	/**
	 * One IEEE comparison of a double expression against a finite double literal, with the
	 * three-valued logic of Trino.
	 *
	 * @param string $operator One of eq, neq, lt, le, gt, ge.
	 */
	public function getIeeeDoubleComparison(string $operator, string $expression, string $literal): string;

	/**
	 * Clause that limits the number of returned groups.
	 */
	public function getGroupLimitClause(int $limit): string;

	/**
	 * Operator/type combinations of the raw filter channel that this dialect applies exactly, meaning
	 * the result equals what Trino would get by re-checking the predicate on the returned values.
	 *
	 * Only these may be dropped from the engine residual, which is what makes the aggregation plan
	 * shape reachable at all. Anything left out stays with the engine and is merely slower, so the
	 * answer for an unproven combination is to omit it.
	 *
	 * @return array<string, string[]> Operator of WhereExactness => list of logical field types.
	 */
	public function getExactWhereOperators(): array;

	/**
	 * Columns whose string comparisons cannot be proven exact, judged by their physical metadata.
	 *
	 * Conservative by contract: a string column without a proven byte-wise native type or collation
	 * belongs in the answer. An empty list is a far stronger claim than it looks - it lets the plugin
	 * drop string filters from the residual and compute aggregates behind them.
	 *
	 * @return string[] Field names.
	 */
	public function getInexactWhereColumns(DatasetSchema $schema): array;

	/**
	 * Semantic flags published in capabilities.
	 */
	public function getSemantics(): DialectSemantics;

	/**
	 * Whether an exact COUNT(DISTINCT) is available. An approximate implementation must answer false.
	 */
	public function supportsExactCountDistinct(): bool;
}
