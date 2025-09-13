<?php

namespace Bitrix\Crm\RepeatSale;

use Bitrix\Crm\Feature;
use Bitrix\Crm\RepeatSale\Statistics\LimitChecker;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

class AvailabilityChecker
{
	public const SEGMENT_INITIALIZATION_OPTION_NAME = 'repeat_sale_segment_initialization';
	public const ENABLE_PENDING_OPTION_NAME = 'repeat_sale_enable_pending';
	private const USE_TIME_LIMIT_OPTION_NAME = 'repeat_sale_use_time_limit';
	private const TIME_LIMIT_START_HOUR_OPTION_NAME = 'repeat_sale_time_limit_start_hour';
	private const TIME_LIMIT_END_HOUR_OPTION_NAME = 'repeat_sale_time_limit_end_hour';

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
		return Option::get('crm', self::SEGMENT_INITIALIZATION_OPTION_NAME, 'N') === 'Y';
	}

	public function isEnablePending(): bool
	{
		return Option::get('crm', self::ENABLE_PENDING_OPTION_NAME, 'N') === 'Y';
	}

	public function hasPermission(): bool
	{
		return RestrictionManager::getRepeatSaleRestriction()->hasPermission();
	}

	public function isAllowedTime(): bool
	{
		if (Option::get('crm', self::USE_TIME_LIMIT_OPTION_NAME, 'N') !== 'Y')
		{
			return true;
		}

		$limit = 10;

		$userOffsets = UserTable::query()
			->setSelect([
				'TIME_ZONE_OFFSET',
			])
			->whereNotNull('TIME_ZONE_OFFSET')
			->setLimit($limit)
			->setCacheTtl(86400 * 7)
			->fetchCollection()
			->getTimeZoneOffsetList()
		;

		$avgOffset = empty($userOffsets) ? 0 : (array_sum($userOffsets) / count($userOffsets));

		$portalDateTime = (new DateTime())->disableUserTime()->add($avgOffset . ' seconds');
		$currentHour = (int)$portalDateTime->format('G');

		$startHour = (int)Option::get('crm', self::TIME_LIMIT_START_HOUR_OPTION_NAME, 18);
		$endHour = (int)Option::get('crm', self::TIME_LIMIT_END_HOUR_OPTION_NAME, 9);

		if ($startHour < $endHour)
		{
			return $currentHour >= $startHour && $currentHour < $endHour;
		}

		return $currentHour >= $startHour || $currentHour < $endHour;
	}
}
