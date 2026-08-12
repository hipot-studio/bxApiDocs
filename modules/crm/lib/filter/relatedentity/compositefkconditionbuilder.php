<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Combines several FK-storage candidates for a single (source, target) pair into one boolean
 * condition. Needed for "mirror pairs" where the relation can be stored in either of two FK
 * columns historically (e.g. Deal-Quote may be recorded as Quote.DEAL_ID and / or Deal.QUOTE_ID).
 * Picking a single candidate and ignoring the others loses rows whose data lives in the column we
 * skipped, so all candidates must be probed together via OR (for the "has" branch) or AND (for
 * the "no" branch).
 *
 * Pair-level NULL handling is delegated to each child via its own buildLegacyCondition() /
 * buildOrmPositivePredicate(): the children encode "(col = 0 OR col IS NULL)" on the legacy "no"
 * branch and the CASE WHEN <positive> THEN 1 ELSE 0 wrapper on the ORM branch already maps
 * UNKNOWN to 0, so the combined expression keeps the same semantics across NULL FK rows.
 */
final class CompositeFkConditionBuilder implements ConditionBuilder
{
	/**
	 * @var list<FkConditionBuilder>
	 */
	private readonly array $candidates;

	/**
	 * @param list<FkConditionBuilder> $candidates Two or more FK candidates configured for the same
	 *     (source, target) pair but different directions / storage columns.
	 */
	public function __construct(array $candidates)
	{
		if (count($candidates) < 2)
		{
			throw new \InvalidArgumentException(
				'CompositeFkConditionBuilder requires at least two FK candidates;'
				. ' single-candidate pairs must use FkConditionBuilder directly.'
			);
		}

		// Stable order by cache key so the emitted SQL does not depend on RelationManager
		// iteration order across requests / installations. Matches the sort applied in getCacheKey().
		usort(
			$candidates,
			static fn(FkConditionBuilder $a, FkConditionBuilder $b): int
				=> strcmp($a->getCacheKey(), $b->getCacheKey())
		);

		$this->candidates = $candidates;
	}

	public function buildLegacyCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation,
		string $sourceTableAlias
	): string
	{
		$parts = [];
		foreach ($this->candidates as $candidate)
		{
			$childSql = $candidate->buildLegacyCondition(
				$sourceTypeId,
				$targetTypeId,
				$hasRelation,
				$sourceTableAlias
			);
			$parts[] = '(' . $childSql . ')';
		}

		// "has" combines positive predicates via OR; "no" combines child-encoded negative predicates
		// via AND. Each child negative predicate already handles NULL FK columns explicitly.
		$glue = $hasRelation ? ' OR ' : ' AND ';

		return '(' . implode($glue, $parts) . ')';
	}

	public function buildOrmCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation
	): array
	{
		$runtimeFieldName = "REL_FILTER_{$sourceTypeId}_{$targetTypeId}";

		$templates = [];
		$fields = [];
		foreach ($this->candidates as $candidate)
		{
			[$template, $candidateFields] = $candidate->buildOrmPositivePredicate();
			$templates[] = '(' . $template . ')';
			foreach ($candidateFields as $field)
			{
				$fields[] = $field;
			}
		}

		// Both "has" and "no" reuse the same OR of positive predicates: the CASE WHEN ... THEN 1
		// ELSE 0 wrapper coerces UNKNOWN to 0, and the filter value (1 for "has", 0 for "no")
		// picks the correct branch. NULL FK rows therefore fall into "no" the same way they do
		// for a single FkConditionBuilder.
		$expression = new ExpressionField(
			$runtimeFieldName,
			'(CASE WHEN (' . implode(' OR ', $templates) . ') THEN 1 ELSE 0 END)',
			$fields
		);

		return [$runtimeFieldName, $expression, $hasRelation ? 1 : 0];
	}

	public function hasAnyRecords(int $sourceTypeId, int $targetTypeId): bool
	{
		foreach ($this->candidates as $candidate)
		{
			if ($candidate->hasAnyRecords($sourceTypeId, $targetTypeId))
			{
				return true;
			}
		}

		return false;
	}

	public function getCacheKey(): string
	{
		$childKeys = [];
		foreach ($this->candidates as $candidate)
		{
			$childKeys[] = $candidate->getCacheKey();
		}
		sort($childKeys); // order-independent fingerprint

		return 'composite_fk|' . implode('#', $childKeys);
	}
}
