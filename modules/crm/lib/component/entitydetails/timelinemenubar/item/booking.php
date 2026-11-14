<?php

declare(strict_types=1);

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;

use Bitrix\Booking\Service\BookingFeature;
use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

class Booking extends Item
{
	public function getId(): string
	{
		return 'booking';
	}

	public function getName(): string
	{
		return \Bitrix\Main\Localization\Loc::getMessage('CRM_TIMELINE_BOOKING');
	}

	public function isAvailable(): bool
	{
		$validTypes = [\CCrmOwnerType::Deal, \CCrmOwnerType::Contact, \CCrmOwnerType::Company];

		$isValidEntityType = in_array($this->getEntityTypeId(), $validTypes, true);

		return \Bitrix\Main\ModuleManager::isModuleInstalled('booking') && $isValidEntityType;
	}

	public function hasTariffRestrictions(): bool
	{
		return !$this->isFeatureEnabled();
	}

	public function prepareSettings(): array
	{
		$entity = [
			'id' => $this->getEntityId(),
			'code' => \CCrmOwnerType::resolveName($this->getEntityTypeId()),
			'module' => 'crm',
		];
		$feature = [
			'id' => $this->getFeatureId(),
			'isEnabled' => $this->isFeatureEnabled(),
		];

		if ($this->getEntityTypeId() === \CCrmOwnerType::Company)
		{
			// pick up the linked contact
			$contacts = Container::getInstance()
				->getFactory($this->getEntityTypeId())
				?->getItem($this->getEntityId(), ['CONTACTS'])
				?->getContactIds()
			;

			if (!empty($contacts))
			{
				return [
					'entities' => [
						[
							'id' => $contacts[0],
							'code' => \CCrmOwnerType::ContactName,
							'module' => 'crm'
						],
						$entity,
					],
					'feature' => $feature,
				];
			}
		}

		return [
			'entities' => [
				$entity,
			],
			'feature' => $feature,
		];
	}

	private function isFeatureEnabled(): bool
	{
		$featureId = $this->getFeatureId();

		if (Loader::includeModule('booking') && $featureId)
		{
			return BookingFeature::isFeatureEnabled($featureId);
		}

		return false;
	}

	private function getFeatureId(): string|null
	{
		if (Loader::includeModule('booking'))
		{
			return BookingFeature::FEATURE_ID_CRM_CREATE_BOOKING;
		}

		return null;
	}
}
