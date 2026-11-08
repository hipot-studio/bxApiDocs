<?php

declare(strict_types=1);

namespace Bitrix\TimemanMobile\Internal\Integration\Bizproc\Providers;

use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\DayPlanBotProvider as BizprocDayPlanBotProvider;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

final class DayPlanBotProvider
{
	public static function getBotId(): int
	{
		if (!Loader::includeModule('bizproc'))
		{
			return 0;
		}

		if (!class_exists(BizprocDayPlanBotProvider::class))
		{
			return 0;
		}

		return (new BizprocDayPlanBotProvider())->getBotIdByUserId((int)CurrentUser::get()->getId()) ?? 0;
	}
}
