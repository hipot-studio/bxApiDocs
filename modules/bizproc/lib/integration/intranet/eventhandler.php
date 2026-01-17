<?php

namespace Bitrix\Bizproc\Integration\Intranet;

use Bitrix\Bizproc\Integration;
use Bitrix\Bizproc\Public\Activity\Trigger\ContextFields\AbsenceBaseTrigger;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

/**
 * Event handlers for module Intranet
 */
class EventHandler
{
	/**
	 * @param Event $event
	 * @return void
	 */
	public static function onSettingsProvidersCollect(Main\Event $event): void
	{
		$providers = $event->getParameter('providers');
		$provider = new Integration\Intranet\Settings\AutomationSettingsPageProvider();

		$employeeProvider = array_values(
			array_filter(
				$providers ?? [],
				fn($item) => $item->getType() === 'employee'
			)
		)[0] ?? null;

		if ($employeeProvider)
		{
			$provider->setSort($employeeProvider->getSort() + 5);
		}

		$providers[$provider->getType()] = $provider;

		$event->addResult(new Main\EventResult(Main\EventResult::SUCCESS, ['providers' => $providers]));
	}

	public static function onAddAbsence(Main\Event $event)
	{
		if (!Loader::includeModule('timeman'))
		{
			return;
		}

		$absenceType = (string)$event->getParameter('absenceType');
		$fields = [
			AbsenceBaseTrigger::FIELD_USER_ID => (int)$event->getParameter('userId'),
			AbsenceBaseTrigger::FIELD_ACTIVE_FROM => (string)$event->getParameter('activeFrom'),
			AbsenceBaseTrigger::FIELD_ACTIVE_TO => (string)$event->getParameter('activeTo'),
		];

		switch ($absenceType)
		{
			case 'LEAVESICK':
				$triggerName = 'AbsenceLeaveSickTrigger';
				break;
			case 'VACATION':
				$triggerName = 'AbsenceVacationTrigger';
				break;
			default:
				return;
		}

		Starter::getByScenario(Scenario::onEvent)
			->setContext(new ContextDto('timeman'))
			->addEvent($triggerName, [], $fields)
			->start()
		;
	}
}
