<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

/**
 * Point visibility check of a template in the "Regular tasks" section (Q-1, P1.T2).
 *
 * Asks the list itself instead of repeating its predicate: the section shows the rules of the
 * viewer - his own by membership, authorship or an explicit grant - and only those he may read
 * ({@see \Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder\MembershipVisibilityModifier}).
 * Both planes must agree - a row the list shows must have a readable schedule, and a row it hides
 * must not become readable through the point check - and a shared predicate is what keeps them
 * from drifting apart.
 *
 * Plane-consistency invariant (Q-1): scoped row/card visibility does NOT grant edit rights.
 * Any change still goes through `TemplateAccessService::canSave()`; membership grants no write
 * rights (AC-S-005).
 */
class SectionVisibilityService
{
	public function __construct(
		private readonly ReplicationRowService $rowService,
	)
	{
	}

	/**
	 * Whether the user can read the template in the section context.
	 */
	public function canReadInSection(int $templateId, int $userId): bool
	{
		if ($templateId <= 0 || $userId <= 0)
		{
			return false;
		}

		return $this->rowService->containsTemplate($this->buildQuery($userId), $templateId);
	}

	/**
	 * Keeps only the passed ids that are visible to the user in the section.
	 *
	 * @param int[] $templateIds
	 * @return int[]
	 */
	public function filterReadableInSection(array $templateIds, int $userId): array
	{
		$templateIds = array_values(array_filter(
			array_unique(array_map('intval', $templateIds)),
			static fn (int $templateId): bool => $templateId > 0,
		));
		if ($templateIds === [] || $userId <= 0)
		{
			return [];
		}

		$visibleTemplateIds = array_flip($this->getVisibleTemplateIds($templateIds, $userId));

		return array_values(array_filter(
			$templateIds,
			static fn (int $templateId): bool => isset($visibleTemplateIds[$templateId]),
		));
	}

	/**
	 * @param int[] $templateIds already normalized and bounded by the caller
	 * @return int[]
	 */
	protected function getVisibleTemplateIds(array $templateIds, int $userId): array
	{
		return $this->rowService->getSliceTemplateIds(
			$this->buildQuery($userId, $templateIds),
			count($templateIds),
		);
	}

	/**
	 * The section slice of the viewer regardless of the recurrence state: the point check answers
	 * about a rule as such, while active/paused is a filter of the list.
	 *
	 * @param int[] $templateIds
	 */
	private function buildQuery(int $userId, array $templateIds = []): SectionListQuery
	{
		return new SectionListQuery(
			viewerId: $userId,
			replicateActive: null,
			templateIds: $templateIds,
		);
	}
}
