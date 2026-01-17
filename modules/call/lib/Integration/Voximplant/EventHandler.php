<?php

namespace Bitrix\Call\Integration\Voximplant;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Voximplant\StatisticTable;
use Bitrix\Main\Loader;

class EventHandler
{
	/**
	 * Handle Voximplant statistic record creation
	 * @event 'voximplant:onAfterStatisticAdd'
	 * @see CVoxImplantHistory::Add
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onAfterStatisticAdd(Event $event): EventResult
	{
		$parameters = $event->getParameters();
		$statisticId = $parameters['statisticId'] ?? null;
		$participants = $parameters['participants'] ?? [];
		$initiatorId = $parameters['initiatorId'] ?? 0;

		if (!$statisticId)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		// Process the call in background to avoid slowing down Voximplant workflow
		self::processVoximplantCall($statisticId, $participants, $initiatorId);

		return new EventResult(EventResult::SUCCESS);
	}

	/**
	 * Process Voximplant call data and create call log entries
	 */
	private static function processVoximplantCall(int $statisticId, array $participants, int $initiatorId): void
	{
		if (!Loader::includeModule('voximplant'))
		{
			return;
		}

		// Get the actual statistic record to ensure we have complete data
		$statisticRecord = StatisticTable::getById($statisticId)->fetch();
		if (!$statisticRecord)
		{
			return;
		}

		// Prepare event data with participants and initiatorId
		$eventData = [
			'fields' => $statisticRecord,
			'participants' => $participants,
			'initiatorId' => $initiatorId
		];

		$callProcessor = new CallProcessor();
		$callProcessor->processCall($eventData);
	}
}
