<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\SalesCenterManager;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;
use Bitrix\Salescenter\Restriction\ToolAvailabilityManager;
use CCrmOwnerType;

final class SalesCenter extends ContentProvider
{
	private const EXCLUDED_ENTITY_TYPES = [
		// sales types
		CCrmOwnerType::StoreDocument,
		CCrmOwnerType::Order,
		CCrmOwnerType::OrderPayment,
		CCrmOwnerType::OrderShipment,
		CCrmOwnerType::ShipmentDocument,

		// sign types
		CCrmOwnerType::SmartDocument,
		CCrmOwnerType::SmartB2eDocument,

		// dont support payments at all types
		CCrmOwnerType::Quote,
	];

	public function getKey(): string
	{
		return 'salescenter';
	}

	public function isShown(): bool
	{
		return
			$this->getContext()->getEntityTypeId() !== null
			&& !in_array($this->getContext()->getEntityTypeId(), self::EXCLUDED_ENTITY_TYPES, true)
			&& SalesCenterManager::getInstance()->isEnabled()
		;
	}

	public function isEnabled(): bool
	{
		return $this->isShown() && SalesCenterManager::getInstance()->isShowApplicationInSmsEditor();
	}

	public function isLocked(): bool
	{
		return $this->isShown() && !ToolAvailabilityManager::getInstance()->checkSalescenterAvailability();
	}

	public function jsonSerialize(): array
	{
		return [
			...parent::jsonSerialize(),
			'mode' => $this->getContext()->getEntityTypeId() === \CCrmOwnerType::Deal ? 'payment_delivery' : 'payment',
		];
	}
}
