<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Agent;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMaintenanceService;

final class AppChatMaintenanceAgent
{
	public static function run(): string
	{
		ServiceLocator::getInstance()->get(AppChatMaintenanceService::class)->run();

		return self::class . '::run();';
	}
}
