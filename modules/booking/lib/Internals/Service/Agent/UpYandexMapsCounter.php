<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Agent;

use Bitrix\Booking\Command\Counter\UpCounterCommand;
use Bitrix\Booking\Internals\Model\BookingTable;
use Bitrix\Booking\Internals\Model\ResourceDataTable;
use Bitrix\Booking\Internals\Model\ScorerTable;
use Bitrix\Booking\Internals\Service\CounterDictionary;
use Bitrix\Booking\Internals\Container;
use Bitrix\Main\Application;

class UpYandexMapsCounter
{
	public static function execute(): string
	{
		$yandexAvailabilityService = Container::getYandexAvailabilityService();
		if (!$yandexAvailabilityService->isAvailable())
		{
			return '';
		}

		$affectedUserIds = static::getUserIds();

		foreach ($affectedUserIds as $affectedUserId)
		{
			(new UpCounterCommand(
				entityId: 0,
				type: CounterDictionary::BookingNewYandexMaps,
				userId: $affectedUserId,
			))->run();

			\CUserCounter::Set(
				$affectedUserId,
				CounterDictionary::LeftMenu->value,
				Container::getCounterRepository()->get($affectedUserId, CounterDictionary::Total),
				'**',
			);
		}

		return '';
	}

	private static function getUserIds(): array
	{
		$result = [];

		$sqlHelper = Application::getConnection()->getSqlHelper();

		$list = Application::getConnection()->query("
			SELECT DISTINCT BRD.CREATED_BY
			FROM " . ResourceDataTable::getTableName() . " BRD
			WHERE
				NOT EXISTS (
					SELECT 1
					FROM " . ScorerTable::getTableName() . "
					WHERE
						USER_ID = BRD.CREATED_BY
						AND ENTITY_ID = 0
						AND TYPE = '" . $sqlHelper->forSql(CounterDictionary::BookingNewYandexMaps->value) . "'
				)
				AND BRD.CREATED_BY > 0
			UNION
			SELECT DISTINCT BB.CREATED_BY
			FROM " . BookingTable::getTableName() . " BB
			WHERE
				NOT EXISTS (
					SELECT 1
					FROM " . ScorerTable::getTableName() . "
					WHERE
						USER_ID = BB.CREATED_BY
						AND ENTITY_ID = 0
					  	AND TYPE = '" . $sqlHelper->forSql(CounterDictionary::BookingNewYandexMaps->value) . "'
				)
				AND BB.CREATED_BY > 0
		");

		while ($row = $list->fetch())
		{
			$result[] = (int)$row['CREATED_BY'];
		}

		return $result;
	}
}
