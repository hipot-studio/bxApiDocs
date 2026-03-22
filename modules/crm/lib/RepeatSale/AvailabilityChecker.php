<?php

namespace Bitrix\Crm\RepeatSale;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\Rest\Marketplace\Client;
use Bitrix\Crm\RepeatSale\Statistics\LimitChecker;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class AvailabilityChecker
{
	private const MODULE_NAME = 'crm';
	public const SEGMENT_INITIALIZATION_OPTION_NAME = 'repeat_sale_segment_initialization';
	public const ENABLE_PENDING_OPTION_NAME = 'repeat_sale_enable_pending';
	private const DEBUG_MODE_OPTION_NAME = 'repeat_sale_debug_mode';

	public function isAvailable(): bool
	{
		return
			$this->isEnabled()
			&& !$this->isSegmentsInitializationProgress()
			&& $this->isItemsCountsLessThenLimit()
		;
	}

	public function isEnabled(): bool
	{
		$region = mb_strtolower(Application::getInstance()->getLicense()->getRegion() ?? 'en');
		if ($region === 'cn')
		{
			return false;
		}

		$isFeatureEnabled = Feature::enabled(Feature\RepeatSale::class);
		$intranetToolManager = Container::getInstance()->getIntranetToolsManager();

		return $isFeatureEnabled && $intranetToolManager->checkRepeatSaleAvailability();
	}

	public function isItemsCountsLessThenLimit(): bool
	{
		return !LimitChecker::getInstance()->isLimitExceeded();
	}

	public function isSegmentsInitializationProgress(): bool
	{
		return Option::get(self::MODULE_NAME, self::SEGMENT_INITIALIZATION_OPTION_NAME, 'N') === 'Y';
	}

	public function isEnablePending(): bool
	{
		return Option::get(self::MODULE_NAME, self::ENABLE_PENDING_OPTION_NAME, 'N') === 'Y';
	}

	public function hasPermission(): bool
	{
		return RestrictionManager::getRepeatSaleRestriction()->hasPermission();
	}

	public function isAiSegmentsAvailable(): bool
	{
		if (!$this->isEnabled())
		{
			return false;
		}

		if (!Feature::enabled(Feature\RepeatSaleAiSegment::class))
		{
			return false;
		}

		return $this->isDebugMode() || !(new Client())->isMarketOverdue();
	}

	private function isDebugMode(): bool
	{
		return Option::get(self::MODULE_NAME, self::DEBUG_MODE_OPTION_NAME, 'N') === 'Y';
	}
}
