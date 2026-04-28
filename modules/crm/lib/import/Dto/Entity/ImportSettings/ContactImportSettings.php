<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use Bitrix\Crm\Import\Contract\ImportSettings\HasDuplicateControlInterface;
use Bitrix\Crm\Import\Contract\ImportSettings\HasRequisiteImportInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\DuplicateControl;
use Bitrix\Crm\Import\Dto\Entity\RequisiteOptions;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Item;
use CCrmOwnerType;
use CCrmStatus;

final class ContactImportSettings extends AbstractImportSettings implements HasRequisiteImportInterface, HasDuplicateControlInterface
{
	use Trait\HasRequisiteOptions;
	use Trait\HasDuplicateControl;

	private Origin $origin = Origin::Custom;
	private string $headerLanguage = LANGUAGE_ID;
	private ?string $defaultContactType = null;
	private ?string $defaultSource = null;
	private string $defaultDescription = '';
	private bool $defaultOpened = false;
	private bool $defaultExportNew = true;

	public function __construct()
	{
		parent::__construct();

		$this->defaultContactType = array_key_first(CCrmStatus::GetStatusList('CONTACT_TYPE'));
		$this->defaultSource = array_key_first(CCrmStatus::GetStatusList('SOURCE'));

		$this->requisiteOptions = new RequisiteOptions($this->getEntityTypeId());
		$this->duplicateControl = new DuplicateControl($this->getEntityTypeId());
	}

	public function getEntityTypeId(): int
	{
		return CCrmOwnerType::Contact;
	}

	public function getOrigin(): Origin
	{
		return $this->origin;
	}

	public function setOrigin(Origin $origin): self
	{
		$this->origin = $origin;

		return $this;
	}

	public function getHeaderLanguage(): string
	{
		return $this->headerLanguage;
	}

	public function setHeaderLanguage(string $headerLanguage): self
	{
		$this->headerLanguage = $headerLanguage;

		return $this;
	}

	public function getDefaultContactType(): ?string
	{
		return $this->defaultContactType;
	}

	public function setDefaultContactType(?string $defaultContactType): self
	{
		$this->defaultContactType = $defaultContactType;

		return $this;
	}

	public function getDefaultSource(): ?string
	{
		return $this->defaultSource;
	}

	public function setDefaultSource(?string $defaultSource): self
	{
		$this->defaultSource = $defaultSource;

		return $this;
	}

	public function getDefaultDescription(): string
	{
		return $this->defaultDescription;
	}

	public function setDefaultDescription(string $defaultDescription): self
	{
		$this->defaultDescription = $defaultDescription;

		return $this;
	}

	public function isDefaultOpened(): bool
	{
		return $this->defaultOpened;
	}

	public function setDefaultOpened(bool $defaultOpened): self
	{
		$this->defaultOpened = $defaultOpened;

		return $this;
	}

	public function isDefaultExportNew(): bool
	{
		return $this->defaultExportNew;
	}

	public function setDefaultExportNew(bool $defaultExportNew): self
	{
		$this->defaultExportNew = $defaultExportNew;

		return $this;
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			'origin' => $this->origin->value,
			'headerLanguage' => $this->headerLanguage,
			'defaultContactType' => $this->defaultContactType,
			'defaultSource' => $this->defaultSource,
			'defaultDescription' => $this->defaultDescription,
			'defaultOpened' => $this->defaultOpened,
			'defaultExportNew' => $this->defaultExportNew,
			...$this->requisiteOptions->toArray(),
			...$this->duplicateControl->toArray(),
		];
	}

	public function fill(array $importSettings): static
	{
		parent::fill($importSettings);

		if (isset($importSettings['origin']) && Origin::tryFrom($importSettings['origin']) !== null)
		{
			$this->setOrigin(Origin::tryFrom($importSettings['origin']));
		}

		if (isset($importSettings['headerLanguage']) && is_string($importSettings['headerLanguage']))
		{
			$this->setHeaderLanguage($importSettings['headerLanguage']);
		}

		if (isset($importSettings['defaultContactType']) && is_string($importSettings['defaultContactType']))
		{
			$this->setDefaultContactType($importSettings['defaultContactType']);
		}

		if (isset($importSettings['defaultSource']) && is_string($importSettings['defaultSource']))
		{
			$this->setDefaultSource($importSettings['defaultSource']);
		}

		if (isset($importSettings['defaultDescription']) && is_string($importSettings['defaultDescription']))
		{
			$this->setDefaultDescription($importSettings['defaultDescription']);
		}

		if (isset($importSettings['defaultOpened']))
		{
			$this->setDefaultOpened((bool)$importSettings['defaultOpened']);
		}

		if (isset($importSettings['defaultExportNew']))
		{
			$this->setDefaultExportNew((bool)$importSettings['defaultExportNew']);
		}

		$this->requisiteOptions->fill($importSettings);
		$this->duplicateControl->fill($importSettings);

		return $this;
	}

	public function applyDefaultValues(array $values): array
	{
		$values = parent::applyDefaultValues($values);

		$defaultValues = [
			Item::FIELD_NAME_TYPE_ID => $this->defaultContactType,
			Item::FIELD_NAME_SOURCE_ID => $this->defaultSource,
			Item::FIELD_NAME_SOURCE_DESCRIPTION => $this->defaultDescription,
			Item::FIELD_NAME_OPENED => $this->defaultOpened,
			Item\Contact::FIELD_NAME_EXPORT => $this->defaultExportNew,
		];

		foreach ($defaultValues as $fieldId => $defaultValue)
		{
			if (($values[$fieldId] ?? null) === null)
			{
				$values[$fieldId] = $defaultValue;
			}
		}

		return $values;
	}
}
