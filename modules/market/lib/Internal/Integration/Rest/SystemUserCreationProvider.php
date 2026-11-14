<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Service\AppSystemUserDecisionService;

class SystemUserCreationProvider
{
	public function isCreationEnabled(): bool
	{
		return
			Loader::includeModule('rest')
			&& class_exists(AppSystemUserDecisionService::class)
			&& (new AppSystemUserDecisionService())->isCreationEnabled()
		;
	}
}
