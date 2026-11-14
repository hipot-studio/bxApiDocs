<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class AiTextEngineAvailablePolicy implements SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		$allowed = AIManager::isEngineAvailable(EventHandler::ENGINE_CATEGORY);
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_AI_TEXT_ENGINE_AVAILABLE_DENIED'),
				'CRM_SETTINGS_POLICY_AI_TEXT_ENGINE_AVAILABLE_DENIED',
			),
		);
	}
}
