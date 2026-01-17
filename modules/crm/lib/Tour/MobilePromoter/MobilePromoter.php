<?php

namespace Bitrix\Crm\Tour\MobilePromoter;

use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Data\Cache;
use CUserOptions;

abstract class MobilePromoter extends Base
{
	protected const OPTION_NAME_SHOW_COUNT = 'mobile_promoter_show_count';
	protected const OPTION_NAME_LAST_SHOW_TIME = 'mobile_promoter_last_show_time';
	protected int $numberOfViewsLimit = 1;
	protected const CACHE_TTL = 6 * 3600;
	protected const CACHE_DIR = '/crm/Tour/MobilePromoter/';

	protected function canShow(): bool
	{
		return \Bitrix\Main\Loader::includeModule('mobile') && $this->canShowByLimits();
	}

	protected function getComponentTemplate(): string
	{
		return 'mobile_promoter';
	}

	protected function getOptions(): array
	{
		return [
			'numberOfViews' => $this->getNumberOfViews(),
			'optionCategory' => $this->getOptionCategory(),
			'optionNameShowCount' => static::OPTION_NAME_SHOW_COUNT,
			'optionNameLastShowTime' => static::OPTION_NAME_LAST_SHOW_TIME,
			'title' => $this->getTitle(),
			'content' => $this->getContent(),
			'analytics' => $this->getAnalytics(),
			'link' => $this->getLink(),
		];
	}

	protected function canShowByLimits(): bool
	{
		$now = time();
		$lastShowTime = $this->getLastShowTime();
		$numberOfViews = $this->getNumberOfViews();

		return $numberOfViews < $this->numberOfViewsLimit && strtotime('+ 1 days', $lastShowTime) < $now;
	}

	protected function getNumberOfViews(): ?int
	{
		return (int)CUserOptions::GetOption($this->getOptionCategory(), static::OPTION_NAME_SHOW_COUNT);
	}

	protected function getLastShowTime(): ?int
	{
		return (int)CUserOptions::GetOption($this->getOptionCategory(), static::OPTION_NAME_LAST_SHOW_TIME);
	}

	protected function getTitle(): string
	{
		return '';
	}

	protected function getContent(): string
	{
		return '';
	}

	protected function hasItemsByAssigned(int $entityTypeId): bool
	{
		$userId = \Bitrix\Crm\Service\Container::getInstance()->getContext()->getUserId();

		$cache = Cache::createInstance();
		if ($cache->initCache(
			self::CACHE_TTL,
			'crm.tour.mobile-promoter.hasItemsByAssigned.' . $entityTypeId . '.' . $userId,
			self::CACHE_DIR,
		))
		{
			$hasItemsByAssigned = (bool)$cache->getVars();
		}
		else
		{
			$hasItemsByAssigned = \Bitrix\Crm\Service\Container::getInstance()
				->getFactory($entityTypeId)
				?->checkIfTotalItemsCountExceeded(
					limit: 1,
					filter: [
						\Bitrix\Crm\Item::FIELD_NAME_ASSIGNED => $userId,
					],
				) ?? false
			;

			$cache->startDataCache();
			$cache->endDataCache($hasItemsByAssigned);
		}

		return $hasItemsByAssigned;
	}

	protected function getAnalytics(): array
	{
		return [];
	}

	protected function getIntent(): string
	{
		return 'preset_crm';
	}

	protected function getLink()
	{
		if (\Bitrix\Main\Loader::includeModule('mobile'))
		{
			return \Bitrix\Mobile\Deeplink::getAuthLink($this->getIntent());
		}

		return '';
	}
}
