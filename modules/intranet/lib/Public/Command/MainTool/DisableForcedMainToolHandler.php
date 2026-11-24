<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\MainTool;

use Bitrix\Intranet\Internal\Repository\MenuSettingsRepository;
use Bitrix\Main\Result;

class DisableForcedMainToolHandler
{
	public function __invoke(DisableForcedMainToolCommand $command): Result
	{
		(new MenuSettingsRepository())->setForced($command->siteId, false);

		return new Result();
	}
}
