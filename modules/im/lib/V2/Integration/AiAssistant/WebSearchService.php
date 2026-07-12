<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant;

use Bitrix\AI\Tuning\Manager as AITuningManager;
use Bitrix\AiAssistant\Integrations\AI\WebSearchSettings;
use Bitrix\AiAssistant\RemoteMcp\RemoteMcpFeature;
use Bitrix\Main\Loader;

class WebSearchService
{
	public function isEnabledByAdmin(): bool
	{
		if (!Loader::includeModule('ai') || !Loader::includeModule('aiassistant'))
		{
			return false;
		}

		if (!class_exists(WebSearchSettings::class))
		{
			return false;
		}

		$settings = AITuningManager::getTuningStorage();

		return (bool)($settings[WebSearchSettings::ITEM_CHAT] ?? true);
	}

	public function isAllowedByTariff(): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		if (!class_exists(RemoteMcpFeature::class))
		{
			return false;
		}

		$feature = RemoteMcpFeature::getInstance();

		return $feature->isAvailableByTariff() && $feature->isAvailableBySubscription();
	}
}
