<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use Bitrix\Crm\Import\Contract\ImportSettings\HasDuplicateControlInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\DuplicateControl;
use CCrmOwnerType;

final class LeadImportSettings extends AbstractImportSettings implements HasDuplicateControlInterface
{
	use Trait\HasDuplicateControl;

	public function __construct()
	{
		parent::__construct();

		$this->duplicateControl = new DuplicateControl($this->getEntityTypeId());
	}

	public function getEntityTypeId(): int
	{
		return CCrmOwnerType::Lead;
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			...$this->duplicateControl->toArray(),
		];
	}

	public function fill(array $importSettings): static
	{
		parent::fill($importSettings);

		$this->duplicateControl->fill($importSettings);

		return $this;
	}
}
