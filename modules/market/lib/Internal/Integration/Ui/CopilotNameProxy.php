<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Ui;

use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class CopilotNameProxy extends BaseService
{
	protected static ?string $copilotName = null;

	public function getCopilotName(): ?string
	{
		if (
			!static::isAvailable()
			|| !class_exists(CopilotNameService::class)
		)
		{
			return null;
		}

		return static::$copilotName ??= (new CopilotNameService())->getCopilotName();
	}
}
