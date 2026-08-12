<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Mobileapp\EventHandler;

use Bitrix\Intranet\Enum\UserRole;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

final class OnBuildEnvVariable
{
	public static function handle(): EventResult
	{
		$values = [
			'isGuest' => self::isGuest(),
		];

		if (Loader::includeModule('ui') && class_exists(CopilotNameService::class))
		{
			$copilotNameService = new CopilotNameService();
			$values['aiName'] = $copilotNameService->getCopilotName();
			$values['aiAgentName'] = $copilotNameService->getCopilotAgentName();
		}

		return new EventResult(
			EventResult::SUCCESS,
			$values,
			'mobile',
		);
	}

	private static function isGuest(): bool
	{
		global $USER;

		return (isset($USER) && $USER->GetParam('EXTERNAL_AUTH_ID') === UserRole::IM_GUEST->value);
	}
}
