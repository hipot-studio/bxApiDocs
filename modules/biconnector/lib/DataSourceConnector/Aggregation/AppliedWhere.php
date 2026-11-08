<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * The WHERE the portal actually compiled, echoed back as a fingerprint (design §5.11.3, §6.8).
 *
 * `X-Bitrix-Bi-Filters-Applied` is one boolean for the whole set and stays `Y` even when a rule was
 * silently skipped for an unknown field, so it cannot carry this guarantee. Once the plugin drops
 * proven conjuncts from the engine residual, nothing re-checks them, and the only remaining safeguard
 * is the portal stating exactly what it applied.
 *
 * The object is filled while the filter is assembled but is only turned into a fingerprint after the
 * SQL has actually compiled: at `$canBeFiltered = false` the WHERE is not printed at all, and
 * confirming rules that never reached the statement would be the very failure this guards against.
 *
 * The order of groups, of rules inside a group and of values inside a rule is preserved as received.
 * The client canonicalizes the filter while building the request, so what arrives is already in
 * canonical order; sorting it again with a different comparator is exactly how the two sides diverge.
 */
final class AppliedWhere
{
	/** @var array{startDate: string, endDate: string, column: string}|null */
	private ?array $dateRange = null;

	/**
	 * Groups of rules, nested exactly as the request nested them.
	 *
	 * The nesting is not decoration: rules inside a group are combined with OR and the groups with AND,
	 * so flattening them would let two different predicates share one fingerprint.
	 *
	 * @var array<int, array<int, array{fieldName: string, operator: string, type: string, values: string[]}>>
	 */
	private array $groups = [];

	/**
	 * The canonical empty WHERE: what a request with no filter, or one whose filter did not compile,
	 * confirms.
	 */
	public static function createEmpty(): self
	{
		return new self();
	}

	public function setDateRange(string $startDate, string $endDate, string $column): void
	{
		$this->dateRange = [
			'startDate' => $startDate,
			'endDate' => $endDate,
			'column' => $column,
		];
	}

	/**
	 * Records one group of rules that actually became part of the filter.
	 *
	 * An empty group is dropped: a group whose every rule was skipped never reaches the statement.
	 *
	 * @param array<int, array{fieldName: string, operator: string, type: string, values: string[]}> $rules
	 */
	public function addGroup(array $rules): void
	{
		if ($rules === [])
		{
			return;
		}

		$this->groups[] = array_values($rules);
	}

	/**
	 * One rule in the wire form the other side reproduces from what it sent.
	 *
	 * The values are kept as they arrived, not as the portal rewrote them for SQL.
	 *
	 * @param string[] $values
	 *
	 * @return array{fieldName: string, operator: string, type: string, values: string[]}
	 */
	public static function rule(string $fieldName, string $operator, string $type, array $values): array
	{
		return [
			'fieldName' => $fieldName,
			'operator' => $operator,
			'type' => $type,
			'values' => array_values($values),
		];
	}

	public function isEmpty(): bool
	{
		return $this->dateRange === null && $this->groups === [];
	}

	/**
	 * The normative object both sides canonicalize.
	 */
	public function toArray(): array
	{
		return [
			'dateRange' => $this->dateRange,
			'dimensionsFilters' => array_values($this->groups),
		];
	}

	public function getFingerprint(): string
	{
		return CanonicalJson::fingerprint($this->toArray());
	}
}
