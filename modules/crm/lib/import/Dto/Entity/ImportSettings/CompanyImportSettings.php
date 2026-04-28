<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use Bitrix\Crm\Import\Contract\ImportSettings\HasDuplicateControlInterface;
use Bitrix\Crm\Import\Contract\ImportSettings\HasRequisiteImportInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\DuplicateControl;
use Bitrix\Crm\Import\Dto\Entity\RequisiteOptions;
use CCrmOwnerType;

final class CompanyImportSettings extends AbstractImportSettings implements HasRequisiteImportInterface, HasDuplicateControlInterface
{
	use Trait\HasRequisiteOptions;
	use Trait\HasDuplicateControl;

	public function __construct()
	{
		parent::__construct();

		$this->requisiteOptions = new RequisiteOptions($this->getEntityTypeId());
		$this->duplicateControl = new DuplicateControl($this->getEntityTypeId());
	}

	public function getEntityTypeId(): int
	{
		return CCrmOwnerType::Company;
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			...$this->requisiteOptions->toArray(),
			...$this->duplicateControl->toArray(),
		];
	}

	public function fill(array $importSettings): static
	{
		parent::fill($importSettings);

		$this->requisiteOptions->fill($importSettings);
		$this->duplicateControl->fill($importSettings);

		return $this;
	}
}
