<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceRepository;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads the source of the trace of the last binding of every item, archived sources included.
 */
class TrackingSourceLoader
{
	private const BATCH_SIZE = 500;

	public function __construct(
		private readonly TraceRepository $traceRepository = new TraceRepository(),
	)
	{
	}

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadTrackingSource())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $item) => $item->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		$ids = array_values(array_unique(array_map(static fn(Item $item) => (int)$item->getId(), $persisted)));
		$sourceIds = [];
		foreach (array_chunk($ids, self::BATCH_SIZE) as $idsChunk)
		{
			$sourceIds += $this->traceRepository->getCurrentSourceIds($entityTypeId, $idsChunk);
		}

		foreach ($persisted as $item)
		{
			$item->internalSet(Item::trackingSourceId, $sourceIds[(int)$item->getId()] ?? null);
		}
	}
}
