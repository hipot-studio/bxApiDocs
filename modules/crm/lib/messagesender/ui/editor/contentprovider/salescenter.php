<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\SalesCenterManager;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\Salescenter\Restriction\ToolAvailabilityManager;
use CCrmOwnerType;

final class SalesCenter extends BaseContentProvider
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

	public function __construct(
		Context $context,
		private readonly bool $canSendMessage = false,
	)
	{
		parent::__construct($context);
	}

	public function getId(): string
	{
		return 'salescenter';
	}

	public function isShown(): bool
	{
		$entityTypeId = $this->getEntityTypeId();

		return $entityTypeId !== null
			&& !in_array($entityTypeId, self::EXCLUDED_ENTITY_TYPES, true)
			&& SalesCenterManager::getInstance()->isEnabled()
			&& SalesCenterManager::getInstance()->isShowApplicationInSmsEditor();
	}

	protected function getCustomData(): array
	{
		return [
			'isLocked' => !ToolAvailabilityManager::getInstance()->checkSalescenterAvailability(),
			'ownerTypeId' => $this->getEntityTypeId(),
			'ownerId' => $this->getEntityId(),
			'mode' => $this->getEntityTypeId() === CCrmOwnerType::Deal
				? 'payment_delivery'
				: 'payment',
			'st' => [
				'tool' => 'crm',
				'category' => 'payments',
				'event' => 'payment_create_click',
				'c_section' => 'crm_sms',
				'c_sub_section' => 'web',
				'type' => 'delivery_payment',
			],
			'canSendMessage' => $this->canSendMessage,
		];
	}
}
