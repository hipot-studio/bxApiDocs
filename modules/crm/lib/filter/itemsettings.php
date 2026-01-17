<?php

namespace Bitrix\Crm\Filter;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Service\Container;

class ItemSettings extends EntitySettings implements ISettingsSupportsCategory
{
	public const FLAG_RECURRING = 1;

	/** @var Type */
	protected $type;
	protected $categoryId = 0;
	protected bool $isRecurring = false;

	public function __construct(array $params, Type $type)
	{
		parent::__construct($params);

		$this->categoryId = (int)(
			$params['categoryId']
			?? $params['categoryID']
			?? $params['CATEGORY_ID']
			?? $this->categoryId
		);

		$this->isRecurring = (bool)($params['isRecurring'] ?? false);

		$this->type = $type;
	}

	public function getType(): Type
	{
		return $this->type;
	}

	public function getEntityTypeID()
	{
		return $this->getType()->getEntityTypeId();
	}

	public function getEntityTypeName(): string
	{
		return Container::getInstance()->getFactory($this->type->getEntityTypeId())->getUserFieldEntityId();
	}

	/**
	 * @inheritDoc
	 */
	public function getUserFieldEntityID(): string
	{
		return $this->getEntityTypeName();
	}

	/**
	 * @inheritDoc
	 */
	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	public function isRecurring(): bool
	{
		return $this->isRecurring;
	}
}
