<?php

namespace Bitrix\Crm\Entity;

use Bitrix\Crm\Category\EditorHelper;
use Bitrix\Crm\Component\EntityDetails\FactoryBased;
use Bitrix\Crm\CustomerType;
use Bitrix\Crm\Service\Container;
use CBitrixComponent;
use CCrmOwnerType;

class EntityEditorOptionBuilder
{
	private ?int $categoryId = null;
	private int $customerType = CustomerType::UNDEFINED;

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
	}

	public function setCategoryId(?int $categoryId = null): self
	{
		$this->categoryId = $categoryId;

		return $this;
	}

	public function setCustomerType(int $customerType): self
	{
		$this->customerType = $customerType;

		return $this;
	}

	public function build(): string
	{
		$option = $this->getMap()[$this->entityTypeId] ?? null;

		if ($this->isLead())
		{
			$prefix = $this->getLeadOptionPrefix();
			$option = $prefix === null ? $option : "{$prefix}_{$option}";
		}

		if ($option === null && $this->isDynamicTypeBasedApproach())
		{
			$option = $this->buildDynamicOption();
			if ($option !== null)
			{
				return $option;
			}
		}

		if ($option === null && $this->isEntityDefined())
		{
			$entityName = mb_strtolower(CCrmOwnerType::ResolveName($this->entityTypeId));
			$option = "{$entityName}_details";
		}

		return (new EditorHelper($this->entityTypeId))
			->getEditorConfigId($this->categoryId, $option ?? '', $this->useUpperCase());
	}

	private function buildDynamicOption(): ?string
	{
		$componentName = Container::getInstance()->getRouter()->getItemDetailComponentName($this->entityTypeId);
		if (!$componentName)
		{
			return null;
		}

		$componentClassName = CBitrixComponent::includeComponentClass($componentName);
		if (!$componentClassName)
		{
			return null;
		}

		/** @var FactoryBased $component */
		$component = new $componentClassName();
		$component->initComponent($componentName);
		$params = [
			'ENTITY_TYPE_ID' => $this->entityTypeId,
		];

		$categoryId = $this->categoryId ?? 0;
		if ($categoryId > 0)
		{
			$params['categoryId'] = $categoryId;
		}

		$component->arParams = $params;
		$component->init();

		return $component->getEditorConfigId();
	}

	private function getMap(): array
	{
		return [
			CCrmOwnerType::Lead => 'lead_details',
			CCrmOwnerType::Deal => 'deal_details',
			CCrmOwnerType::Contact => 'contact_details',
			CCrmOwnerType::Company => 'company_details',
			CCrmOwnerType::Quote => 'QUOTE_details',
			CCrmOwnerType::StoreDocument => 'store_document_details',
			CCrmOwnerType::ShipmentDocument => 'realization_document_delivery_details', // or realization_document_shipment_details ?
		];
	}

	private function getLeadOptionPrefix(): ?string
	{
		$excludedTypes = [
			CustomerType::UNDEFINED,
			CustomerType::GENERAL,
		];

		if (in_array($this->customerType, $excludedTypes, true))
		{
			return null;
		}

		return mb_strtolower(CustomerType::resolveName($this->customerType));
	}

	private function useUpperCase(): bool
	{
		$includedTypes = [
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
		];

		return in_array($this->entityTypeId, $includedTypes, true);
	}

	private function isLead(): bool
	{
		return $this->entityTypeId === CCrmOwnerType::Lead;
	}

	private function isDynamicTypeBasedApproach(): bool
	{
		return CCrmOwnerType::isUseDynamicTypeBasedApproach($this->entityTypeId);
	}

	private function isEntityDefined(): bool
	{
		return CCrmOwnerType::IsDefined($this->entityTypeId);
	}
}
