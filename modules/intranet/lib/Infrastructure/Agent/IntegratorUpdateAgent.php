<?php

namespace Bitrix\Intranet\Infrastructure\Agent;

use Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoService;

class IntegratorUpdateAgent
{
	public static function run(): string
	{
		IntegratorInfoService::createByDefault()->updateIntegratorInfo();

		return self::class . '::run();';
	}
}
