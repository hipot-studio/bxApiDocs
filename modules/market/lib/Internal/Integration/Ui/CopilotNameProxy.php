<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Ui;

use Bitrix\Main\Application;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class CopilotNameProxy extends BaseService
{
	protected static ?string $copilotName = null;

	public function getCopilotName(): string
	{
		if (
			!static::isAvailable()
			|| !class_exists(CopilotNameService::class)
		)
		{
			return Application::getInstance()->getLicense()->isCis() ? 'BitrixGPT' : 'CoPilot';
		}

		return static::$copilotName ??= (new CopilotNameService())->getCopilotName();
	}
}
