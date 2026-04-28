<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Model\LogTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Sync\Entity\EntityFactory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class SyncService
{
	use ContextCustomer;

	private const MODULE_ID = 'im';
	private const ENABLE_OPTION_NAME = 'sync_logger_enable';

	public static function isEnable(): bool
	{
		return Option::get(self::MODULE_ID, self::ENABLE_OPTION_NAME, 'Y') === 'Y';
	}

	public function getChangesFromDate(DateTime $lastDate, ?int $lastId, int $limit): array
	{
		if (!self::isEnable())
		{
			return [];
		}

		$query = isset($lastId)
			? $this->getQuery($lastDate, $lastId, $limit)
			: $this->getQueryWithoutLastId($lastDate, $limit)
		;

		return $this->formatData($query->fetchAll(), $limit);
	}

	private function getQueryWithoutLastId(DateTime $lastDate, int $limit): Query
	{
		return LogTable::query()
			->setSelect(['ID', 'USER_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'EVENT', 'DATE_CREATE'])
			->where('USER_ID', $this->getContext()->getUserId())
			->where('DATE_CREATE', '>=', $lastDate)
			->setLimit($limit)
			->setOrder(['USER_ID' => 'ASC','DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
		;
	}

	private function getQuery(DateTime $lastDate, int $lastId, int $limit): Query
	{
		$filter = [[
			'LOGIC' => 'OR',
			[
				'>DATE_CREATE' => $lastDate,
			],
			[
				'=DATE_CREATE' => $lastDate,
				'>=ID' => $lastId,
			],
		]];

		$subQuery = LogTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $this->getContext()->getUserId())
			->setFilter($filter)
			->setLimit($limit)
			->setOrder(['DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
		;

		$reference = new Reference(
			'FILTERED_IDS',
			\Bitrix\Main\ORM\Entity::getInstanceByQuery($subQuery),
			Join::on('this.ID', 'ref.ID'),
			['join_type' => Join::TYPE_INNER]
		);

		return LogTable::query()
			->setSelect(['ID', 'USER_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'EVENT', 'DATE_CREATE'])
			->registerRuntimeField('FILTERED_IDS', $reference)
			->setOrder(['DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
		;
	}

	private function formatData(array $logEvents, int $limit): array
	{
		$entities = (new EntityFactory())->createEntities($logEvents);
		$rest = $entities->getRestData();
		$rest['navigationData'] = $this->getNavigationData($logEvents, $limit);

		return $rest;
	}

	protected function getNavigationData(array $logEvents, int $limit): array
	{
		$maxDateTime = null;
		$maxTimestamp = 0;
		$lastId = 0;
		foreach ($logEvents as $logEvent)
		{
			$dateCreate = $logEvent['DATE_CREATE'];
			$entityId = (int)$logEvent['ID'];

			if (!$dateCreate instanceof DateTime)
			{
				continue;
			}

			if ($dateCreate->getTimestamp() > $maxTimestamp)
			{
				$maxTimestamp = $dateCreate->getTimestamp();
				$maxDateTime = $dateCreate;
				$lastId = $entityId;
			}
			elseif ($dateCreate->getTimestamp() === $maxTimestamp && $entityId > $lastId)
			{
				$lastId = $entityId;
			}
		}

		return [
			'lastServerDate' => $maxDateTime,
			'hasMore' => count($logEvents) >= $limit,
			'lastId' => $lastId,
		];
	}
}
