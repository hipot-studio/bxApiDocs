<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Access\Activity\ReadOpinionProviders;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject\ActivityFile;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Main\ArgumentException;

/**
 * One page of the activities bound to a parent the caller has let the user read.
 *
 * WHY THE PAGE IS EXACT. With the parent readable, {@see ActivityAccess} lets the user read an activity bound to it
 * unless the provider of the activity refuses. So only the activities of the providers with an opinion about
 * reading ({@see ReadOpinionProviders}) are asked: their identifiers are selected first, decided over a batch
 * ({@see ActivityAccess::preloadForReading()}), and the refused ones are left out of the statement of the page
 * ({@see ActivityFilter::getExcludedIds()}). A refused activity thus neither shortens a page nor shifts the offset,
 * and the queries do not grow with the page or the offset.
 *
 * THE CANDIDATES ARE DECIDED IN PORTIONS ({@see self::CANDIDATES_AT_ONCE}), each a reading of its own that is ended
 * and forgotten before the next one is selected: only the identifiers are kept between the portions. A parent with
 * fewer candidates than a portion is decided by one batch.
 *
 * THE RACE IS CLOSED ON THE PAGE. An activity of such a provider that was no candidate came under the parent or
 * took its provider between the two statements: such activities of the page are decided over one more batch and
 * dropped when refused, so the page is shortened then and only then.
 *
 * The parent itself is the business of the caller, which checks it before anything here is read.
 *
 * @internal
 */
final class ContextActivityListReader
{
	/** Result item key: the {@see Activity} read. */
	public const ITEM_ACTIVITY = ActivityReader::DATA_ACTIVITY;

	/** Result item key: the {@see ActivityFile} metadata of its files, null where the select left them out. */
	public const ITEM_FILES = ActivityReader::DATA_FILES;

	/**
	 * How many candidates are selected and decided at once: no statement of a decision names more activities than
	 * that, and what a portion read to decide is dropped before the next one is selected.
	 */
	private const CANDIDATES_AT_ONCE = 1000;

	/**
	 * @param ActivityAccess|null $access null takes the access model of the hit when it is first needed
	 * @param ReadOpinionProviders|null $readOpinionProviders null reads the registry of the providers of the hit
	 * @param int $candidatesAtOnce how many candidates one portion selects and decides
	 */
	public function __construct(
		private ?ActivityRepository $repository = null,
		private ?ActivityAccess $access = null,
		private ?ReadOpinionProviders $readOpinionProviders = null,
		private ?ActivityReader $reader = null,
		private int $candidatesAtOnce = self::CANDIDATES_AT_ONCE,
	)
	{
	}

	/**
	 * @param ActivityFilter $filter conditions and exclusions without a binding: the binding is the parent
	 * @param string[] $select as {@see ActivityReader::get()} takes it
	 * @return array<int, array{activity: Activity, files: ActivityFile[]|null}> in the order of the sort
	 * @throws ArgumentException the filter carries a binding of its own.
	 */
	public function list(
		int $userId,
		ActivityBinding $parent,
		ActivityFilter $filter,
		ActivitySort $sort,
		int $limit,
		int $offset,
		array $select = [],
	): array
	{
		if ($filter->getBinding() !== null)
		{
			throw new ArgumentException('A contextual list is bound to its parent alone.', 'filter');
		}
		if ($userId <= 0)
		{
			return [];
		}

		$providerIds = $this->getReadOpinionProviders()->getProviderIds();
		$portionSize = max(1, $this->candidatesAtOnce);
		$candidateIds = [];
		$deniedIds = [];
		do
		{
			$portion = $this->getRepository()->getCandidateIds(
				$parent,
				$providerIds,
				(int)end($candidateIds),
				$portionSize,
			);
			array_push($deniedIds, ...$this->decideCandidates($userId, $portion));
			array_push($candidateIds, ...$portion);
		}
		while (count($portion) >= $portionSize);

		$pageFilter = new ActivityFilter(
			$parent,
			$filter->getConditions(),
			[...$filter->getExcludedIds(), ...$deniedIds],
		);
		$pageIds = $this->getRepository()->getIds($pageFilter, $sort, $limit, max(0, $offset));
		$items = $this->getReader()->getManyUnchecked($userId, $pageIds, $select);

		return $this->dropUndecidedRefused($userId, $items, $providerIds, $candidateIds);
	}

	/**
	 * @param int[] $candidateIds
	 * @return int[] the refused ones
	 */
	private function decideCandidates(int $userId, array $candidateIds): array
	{
		if ($candidateIds === [])
		{
			return [];
		}

		$access = $this->getAccess();
		$deniedIds = [];
		try
		{
			$access->preloadForReading($userId, $candidateIds);
			foreach ($candidateIds as $activityId)
			{
				if (!$access->canRead($userId, $activityId))
				{
					$deniedIds[] = $activityId;
				}
			}
		}
		finally
		{
			$access->endReading();
			// decided and not needed again: the memory of the hit must not grow with every candidate
			foreach ($candidateIds as $activityId)
			{
				$access->forgetActivity($activityId);
			}
		}

		return $deniedIds;
	}

	/**
	 * @param array<int, array{activity: Activity, files: ActivityFile[]|null}> $items
	 * @param string[] $providerIds
	 * @param int[] $candidateIds
	 * @return array<int, array{activity: Activity, files: ActivityFile[]|null}>
	 */
	private function dropUndecidedRefused(int $userId, array $items, array $providerIds, array $candidateIds): array
	{
		$withOpinion = array_fill_keys($providerIds, true);
		$decided = array_fill_keys($candidateIds, true);

		$undecidedIds = [];
		foreach ($items as $item)
		{
			$activity = $item[self::ITEM_ACTIVITY];
			$activityId = (int)$activity->getId();
			if (!isset($decided[$activityId]) && isset($withOpinion[(string)$activity->getProviderId()]))
			{
				$undecidedIds[] = $activityId;
			}
		}
		$refused = array_fill_keys($this->decideCandidates($userId, $undecidedIds), true);

		$page = [];
		foreach ($items as $item)
		{
			if (!isset($refused[(int)$item[self::ITEM_ACTIVITY]->getId()]))
			{
				$page[] = $item;
			}
		}

		return $page;
	}

	/** The access model of the hit, so the verdicts match the ones every other scenario of the hit gets. */
	private function getAccess(): ActivityAccess
	{
		return $this->access ??= Container::getInstance()->getActivityAccess();
	}

	private function getReadOpinionProviders(): ReadOpinionProviders
	{
		return $this->readOpinionProviders
			?? new ReadOpinionProviders(Container::getInstance()->getActivityProviderRegistry())
		;
	}

	private function getRepository(): ActivityRepository
	{
		return $this->repository ??= new ActivityRepository();
	}

	private function getReader(): ActivityReader
	{
		return $this->reader ??= new ActivityReader();
	}
}
