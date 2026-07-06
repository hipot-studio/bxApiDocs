<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\UI;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class CopilotService
{
	public static function getName(): string
	{
		if (
			Loader::includeModule('ui')
			&& class_exists(CopilotNameService::class)
		)
		{
			return (new CopilotNameService())->getCopilotName();
		}

		return Application::getInstance()->getLicense()->isCis() ? 'BitrixGPT' : 'CoPilot';
	}
}
