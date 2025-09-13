<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Model\LogTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Sync\Entity\EntityFactory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class SyncService
{
	use ContextCustomer;

	private const OFFSET_INTERVAL_IN_SECONDS = 5;
	private const MODULE_ID = 'im';
	private const ENABLE_OPTION_NAME = 'sync_logger_enable';

	public static function isEnable(): bool
	{
		return Option::get(self::MODULE_ID, self::ENABLE_OPTION_NAME, 'Y') === 'Y';
	}

	public function getChangesFromDate(DateTime $date, int $limit): array
	{
		if (!self::isEnable())
		{
			return [];
		}

		$date = $this->getDateWithOffset($date);
		$logEntities = LogTable::query()
			->setSelect(['ID', 'USER_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'EVENT', 'DATE_CREATE'])
			->where('USER_ID', $this->getContext()->getUserId())
			->where('DATE_CREATE', '>=', $date)
			->setLimit($limit)
			->fetchAll()
		;

		return $this->formatData($logEntities, $limit);
	}

	private function getDateWithOffset(DateTime $date): DateTime
	{
		$offset = self::OFFSET_INTERVAL_IN_SECONDS;
		$date->add("- {$offset} seconds");

		return $date;
	}

	private function formatData(array $logEntities, int $limit): array
	{
		$entities = (new EntityFactory())->createEntities(Event::initByArray($logEntities));
		$rest = $entities->getRestData();

		$rest['hasMore'] = count($logEntities) >= $limit;
		$rest['lastServerDate'] = $this->getLastServerDate($logEntities);

		return $rest;
	}

	protected function getLastServerDate(array $logEntities): ?DateTime
	{
		$maxDateTime = null;
		$maxTimestamp = 0;
		foreach ($logEntities as $logEntity)
		{
			$dateCreate = $logEntity['DATE_CREATE'];

			if (!$dateCreate instanceof DateTime)
			{
				continue;
			}
			if ($dateCreate->getTimestamp() > $maxTimestamp)
			{
				$maxTimestamp = $dateCreate->getTimestamp();
				$maxDateTime = $dateCreate;
			}
		}

		return $maxDateTime;
	}
}
