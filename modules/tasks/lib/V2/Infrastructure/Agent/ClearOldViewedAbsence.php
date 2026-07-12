<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Update\AgentInterface;
use Bitrix\Tasks\Update\AgentTrait;
use Bitrix\Tasks\V2\Public\Command\User\ClearOldViewedAbsenceCommand;

final class ClearOldViewedAbsence implements AgentInterface
{
	use AgentTrait;

	public static function getInterval(): int
	{
		return 60 * 60 * 24 * 7; // 7 days
	}

	public static function execute(): string
	{
		$command = new ClearOldViewedAbsenceCommand(new DateTime());
		$command->run();

		return self::getAgentName();
	}
}
