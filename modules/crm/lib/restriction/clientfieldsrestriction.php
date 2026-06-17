<?php

namespace Bitrix\Crm\Restriction;

use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\NotSupportedException;
use CCrmDeal;
use CCrmOwnerType;

class ClientFieldsRestriction extends Bitrix24QuantityRestriction
{
	protected int $entityTypeId;

	private const RESTRICTION_NAME = 'crm_client_fields_deal_limit';
	private const DEAL_RESTRICTION_SLIDER_CODE = 'limit_crm_filter_deals';
	private const MAX_DEAL_RESTRICTION_SLIDER_CODE = 'limit_crm_filter_50000_fields';
	private const ITEM_RESTRICTION_SLIDER_CODE = 'limit_v2_crm_client_fields_deal_limit_choose_plan';
	private const MAX_ITEM_RESTRICTION_SLIDER_CODE = 'limit_v2_crm_client_fields_deal_limit';

	public function __construct(int $entityTypeId)
	{
		$this->entityTypeId = $entityTypeId;

		$restrictionName = self::RESTRICTION_NAME;
		$limit = max(0, (int)Bitrix24Manager::getVariable($restrictionName));
		$maxLimit = Bitrix24Manager::getMaxVariable($restrictionName);
		$restrictionSliderInfo = [
			'ID' => $this->getSliderCode($limit === $maxLimit),
		];

		parent::__construct($restrictionName, $limit, null, $restrictionSliderInfo);

		$this->load(); // load actual $limit from options
	}

	public function isExceeded(): bool
	{
		$limit = $this->getQuantityLimit();
		if ($limit <= 0)
		{
			return false;
		}
		$count = $this->getCount($this->entityTypeId);

		return ($count > $limit);
	}

	public function getCount(int $entityTypeId): int
	{
		$cacheId = 'crm_client_fields_restriction_count_' . $entityTypeId;

		if ($this->cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			return (int)$this->cache->getVars()['count'];
		}

		$this->cache->startDataCache();

		$count = match (true)
		{
			$entityTypeId === CCrmOwnerType::Deal => CCrmDeal::GetTotalCount(),
			\CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId) => $this->getFactoryItemsCount($entityTypeId),
			default => throw new NotSupportedException('Entity type ' . $entityTypeId . ' is not supported'),
		};

		$this->cache->endDataCache(['count' => $count]);

		return $count;
	}

	private function getFactoryItemsCount(int $entityTypeId): int
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory?->getItemsCount() ?? 0;
	}

	private function getSliderCode(bool $isMaxLimit): string
	{
		if ($this->entityTypeId === CCrmOwnerType::Deal)
		{
			return $isMaxLimit ? self::MAX_DEAL_RESTRICTION_SLIDER_CODE : self::DEAL_RESTRICTION_SLIDER_CODE;
		}

		return $isMaxLimit ? self::MAX_ITEM_RESTRICTION_SLIDER_CODE : self::ITEM_RESTRICTION_SLIDER_CODE;
	}
}
