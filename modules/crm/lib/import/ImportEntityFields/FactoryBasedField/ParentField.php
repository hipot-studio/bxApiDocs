<?php

declare(strict_types=1);

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\FactoryItemValueMapper;
use Bitrix\Crm\Service\ParentFieldManager;

final class ParentField extends AbstractFactoryBasedField
{
	public function __construct(
		int $entityTypeId,
		private readonly int $parentEntityTypeId,
	)
	{
		parent::__construct($entityTypeId);
	}

	public function getId(): string
	{
		return ParentFieldManager::getParentFieldName($this->parentEntityTypeId);
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new FactoryItemValueMapper($this->getId(), $this->parentEntityTypeId))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}
