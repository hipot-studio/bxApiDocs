<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Ai;

use Bitrix\AI\Tuning\Manager;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/** @internal */
class TuningGateway
{
	public function isEnabled(GlobalSetting $setting): bool
	{
		return AIManager::isEnabledInGlobalSettings($setting);
	}

	public function setEnabled(GlobalSetting $setting, bool $enabled): Result
	{
		$result = new Result();
		if (!Loader::includeModule('ai'))
		{
			return $result->addError(new Error('AI module is not available.', 'AI_MODULE_NOT_AVAILABLE'));
		}

		$manager = new Manager();
		$item = $manager->getItem($setting->value);
		if ($item === null)
		{
			return $result->addError(new Error('AI tuning item was not found.', 'AI_TUNING_ITEM_NOT_FOUND'));
		}

		$item->setValue($enabled);
		try
		{
			$manager->save();
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Failed to save AI tuning settings.', 'AI_TUNING_SAVE_FAILED'));
		}

		AIManager::resetGlobalSettingsCache();

		return $result;
	}
}
