<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureReadonlyTrait;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;

abstract class AbstractFactoryBasedField implements ImportEntityFieldInterface
{
	use CanConfigureReadonlyTrait;

	protected readonly Factory $factory;

	public function __construct(
		protected readonly int $entityTypeId,
	)
	{
		$this->factory = Container::getInstance()->getFactory($this->entityTypeId);
	}

	public function getCaption(): string
	{
		return $this->factory->getFieldCaption($this->getId());
	}

	public function isRequired(): bool
	{
		return $this->factory->getFieldsCollection()->getField($this->getId())?->isRequired() ?? false;
	}
}
