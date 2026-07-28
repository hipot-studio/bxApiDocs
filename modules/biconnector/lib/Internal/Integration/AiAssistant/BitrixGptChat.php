<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

/**
 * Single source of truth for whether the BitrixGPT chat widget should be wired into the
 * BI dashboard detail page. Used by the dashboard_detail header/footer templates and the
 * detail component template instead of duplicating the gate inline.
 */
final class BitrixGptChat
{
	public static function isAvailable(): bool
	{
		if (!Feature::isBitrixGptBiConstructorEnabled())
		{
			return false;
		}

		if (!Loader::includeModule('aiassistant') || !Loader::includeModule('im'))
		{
			return false;
		}

		$service = ServiceLocator::getInstance()->get(AiAssistantService::class);
		if (!$service instanceof AiAssistantService)
		{
			return false;
		}

		return $service->isBitrixGptV2Available('bitrixgpt_v2_available')
			&& $service->getBotId() > 0;
	}

	/**
	 * Region-aware product name: "BitrixGPT" in CIS zones, "CoPilot" in the west.
	 */
	public static function getName(): string
	{
		if (!Loader::includeModule('ui'))
		{
			return 'BitrixGPT';
		}

		return (new CopilotNameService())->getCopilotName();
	}
}
