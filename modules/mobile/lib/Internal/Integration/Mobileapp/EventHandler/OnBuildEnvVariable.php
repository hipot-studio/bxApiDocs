<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Mobileapp\EventHandler;

use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

final class OnBuildEnvVariable
{
	public static function handle(): ?EventResult
	{
		if (!Loader::includeModule('ui') || !class_exists(CopilotNameService::class))
		{
			return null;
		}

		return new EventResult(
			EventResult::SUCCESS,
			[
				'aiName' => (new CopilotNameService())->getCopilotName(),
			],
			'mobile',
		);
	}
}
