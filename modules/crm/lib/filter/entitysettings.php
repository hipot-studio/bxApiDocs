<?php
namespace Bitrix\Crm\Filter;

abstract class EntitySettings extends \Bitrix\Main\Filter\EntitySettings
{
	protected bool $isSkipLoadItemsForPartialFields = false;
	protected bool $disableDepartmentSelector = false;

	public function __construct(array $params)
	{
		parent::__construct($params);

		$this->disableDepartmentSelector = $params['disableDepartmentSelector'] ?? false;
		$this->isSkipLoadItemsForPartialFields = $params['isSkipLoadItemsForPartialFields'] ?? false;
	}

	public function getEntityTypeName()
	{
		return $this->getEntityTypeID();
	}

	public function getEntityTypeID()
	{
		return \CCrmOwnerType::Undefined;
	}

	public function isSkipLoadItemsForPartialFields(): bool
	{
		return $this->isSkipLoadItemsForPartialFields;
	}

	public function isDepartmentSelectorDisabled(): bool
	{
		return $this->disableDepartmentSelector;
	}
}
