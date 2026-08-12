<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Agent;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledActionType;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\FullReportReadyTrigger;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main\Loader;

final class FullReportAiGenerator
{
	public static function execute(int $userId, int $executeTime): string
	{
		if (self::isStarterEnabled())
		{
			$fields = [
				FullReportReadyTrigger::FIELD_USER_ID => $userId,
			];

			Starter::getByScenario(Scenario::onEvent)
				->setContext(new ContextDto('timeman'))
				->addEvent('FullReportReadyTrigger', [], $fields)
				->start()
			;
		}

		Container::getInstance()->getScheduledActionService()->complete(
			ScheduledActionType::FullReportAiGenerate->value,
			$userId,
			$executeTime,
		);

		return '';
	}

	private static function isStarterEnabled(): bool
	{
		return Loader::includeModule('bizproc') && class_exists(Starter::class) && Starter::isEnabled();
	}
}
