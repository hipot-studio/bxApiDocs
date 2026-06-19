<?php

namespace Bitrix\ImOpenLines\V2\Queue;

use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\ImOpenLines\Model\ConfigTable;
use Bitrix\ImOpenLines\Model\EO_Config_Collection;
use Bitrix\Main\Type\Collection;

/**
 * @extends Registry<QueueItem>
 */
class Queue extends Registry implements RestConvertible
{
	private const CACHE_TTL = 86400;

	public static function getQueues(): self
	{
		$queue = new self();
		$queueEntities = self::getQueueEntities();

		foreach ($queueEntities as $entity)
		{
			$queue[] = QueueItem::createFromEntity($entity);
		}

		return $queue;
	}

	public static function getQueuesByIds(array $ids): self
	{
		$queue = new self();

		Collection::normalizeArrayValuesByInt($ids);
		if (empty($ids))
		{
			return $queue;
		}

		$queueEntities = ConfigTable::query()
			->setSelect([
				'ID',
				'LINE_NAME',
				'ACTIVE',
				'QUEUE_TYPE',
			])
			->whereIn('ID', $ids)
			->setCacheTtl(self::CACHE_TTL)
			->fetchCollection()
		;

		foreach ($queueEntities as $entity)
		{
			$queue[] = QueueItem::createFromEntity($entity);
		}

		return $queue;
	}

	protected static function getQueueEntities(): EO_Config_Collection
	{
		$query = ConfigTable::query()
			->setSelect([
				'ID',
				'LINE_NAME',
				'ACTIVE',
				'QUEUE_TYPE',
			])
			->setCacheTtl(self::CACHE_TTL)
		;

		return $query->fetchCollection();
	}

	public static function getRestEntityName(): string
	{
		return 'queueItems';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$rest = [];

		foreach ($this as $item)
		{
			$rest[] = $item->toRestFormat();
		}

		return $rest;
	}
}