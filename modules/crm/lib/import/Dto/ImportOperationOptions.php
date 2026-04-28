<?php

namespace Bitrix\Crm\Import\Dto;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\ImportSettings\HasDuplicateControlInterface;
use Bitrix\Crm\Import\Contract\ImportSettings\HasRequisiteImportInterface;
use Bitrix\Crm\Import\Dto\Entity\DuplicateControl;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Dto\Entity\RequisiteOptions;

final class ImportOperationOptions
{
	public function __construct(
		public readonly ReaderInterface $reader,
		public readonly ImportEntityInterface $entity,
		public readonly FieldBindings $fieldBindings,
		public readonly int $startFrom,
		public readonly int $limit,
	)
	{
	}

	public function entityTypeId(): int
	{
		return $this->entity->getSettings()->getEntityTypeId();
	}

	public function entityFields(): array
	{
		return $this->entity->getFields()->getAll();
	}

	public function isFirstRowHasHeaders(): bool
	{
		return $this->entity->getSettings()->isFirstRowHasHeaders();
	}

	public function getDuplicateControl(): ?DuplicateControl
	{
		$importSettings = $this->entity->getSettings();
		if ($importSettings instanceof HasDuplicateControlInterface)
		{
			return $importSettings->getDuplicateControl();
		}

		return null;
	}

	public function getRequisiteOptions(): ?RequisiteOptions
	{
		$importSettings = $this->entity->getSettings();
		if ($importSettings instanceof HasRequisiteImportInterface)
		{
			return $importSettings->getRequisiteOptions();
		}

		return null;
	}
}
