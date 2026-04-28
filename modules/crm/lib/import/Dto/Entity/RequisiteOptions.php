<?php

namespace Bitrix\Crm\Import\Dto\Entity;

use Bitrix\Crm\EntityRequisite;
use Bitrix\Main\NotSupportedException;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class RequisiteOptions implements Arrayable, JsonSerializable
{
	private bool $isImportRequisite = false;
	private int $defaultRequisitePresetId;
	private bool $isRequisitePresetAssociate = true;
	private bool $isRequisitePresetAssociateById = false;
	private bool $isRequisitePresetUseDefault = false;

	private int $searchNextEntity = 0;
	private string $prevEntity = '';

	private array $requisiteDupControlFieldMap = [];

	/**
	 * @param int $entityTypeId Contact or Company
	 * @throws NotSupportedException
	 */
	public function __construct(
		private readonly int $entityTypeId,
	)
	{
		$this->defaultRequisitePresetId = EntityRequisite::getDefaultPresetId($this->entityTypeId);
	}

	public function isImportRequisite(): bool
	{
		return $this->isImportRequisite;
	}

	public function setIsImportRequisite(bool $isImportRequisites): self
	{
		$this->isImportRequisite = $isImportRequisites;

		return $this;
	}

	public function getDefaultRequisitePresetId(): int
	{
		return $this->defaultRequisitePresetId;
	}

	public function setDefaultRequisitePresetId(int $defaultRequisitePresetId): self
	{
		$this->defaultRequisitePresetId = $defaultRequisitePresetId;

		return $this;
	}

	public function isRequisitePresetAssociate(): bool
	{
		return $this->isRequisitePresetAssociate;
	}

	public function setIsRequisitePresetAssociate(bool $isRequisitePresetAssociate): self
	{
		$this->isRequisitePresetAssociate = $isRequisitePresetAssociate;

		return $this;
	}

	public function isRequisitePresetAssociateById(): bool
	{
		return $this->isRequisitePresetAssociateById;
	}

	public function setIsRequisitePresetAssociateById(bool $isRequisitePresetAssociateById): self
	{
		$this->isRequisitePresetAssociateById = $isRequisitePresetAssociateById;

		return $this;
	}

	public function isRequisitePresetUseDefault(): bool
	{
		return $this->isRequisitePresetUseDefault;
	}

	public function setIsRequisitePresetUseDefault(bool $isRequisitePresetUseDefault): self
	{
		$this->isRequisitePresetUseDefault = $isRequisitePresetUseDefault;

		return $this;
	}

	public function getSearchNextEntity(): int
	{
		return $this->searchNextEntity;
	}

	public function resetSearchNextEntity(): self
	{
		$this->searchNextEntity = 0;

		return $this;
	}

	public function setSearchNextEntity(int $searchNextEntity): self
	{
		$this->searchNextEntity = $searchNextEntity;

		return $this;
	}

	public function getPrevEntity(): string
	{
		return $this->prevEntity;
	}

	public function resetPrevEntity(): self
	{
		$this->prevEntity = '';

		return $this;
	}

	public function setPrevEntity(string $prevEntity): self
	{
		$this->prevEntity = $prevEntity;

		return $this;
	}

	public function getRequisiteDupControlFieldMap(): array
	{
		return $this->requisiteDupControlFieldMap;
	}

	public function setRequisiteDupControlFieldMap(array $requisiteDupControlFieldMap): self
	{
		$this->requisiteDupControlFieldMap = $requisiteDupControlFieldMap;

		return $this;
	}

	public function fill(array $importOptions): self
	{
		if (isset($importOptions['isImportRequisite']))
		{
			$this->setIsImportRequisite((bool)$importOptions['isImportRequisite']);
		}

		if (
			isset($importOptions['defaultRequisitePresetId'])
			&& is_string($importOptions['defaultRequisitePresetId'])
		)
		{
			$this->setDefaultRequisitePresetId($importOptions['defaultRequisitePresetId']);
		}

		if (isset($importOptions['isRequisitePresetAssociate']))
		{
			$this->setIsRequisitePresetAssociate((bool)$importOptions['isRequisitePresetAssociate']);
		}

		if (isset($importOptions['isRequisitePresetAssociateById']))
		{
			$this->setIsRequisitePresetAssociateById((bool)$importOptions['isRequisitePresetAssociateById']);
		}

		if (isset($importOptions['isRequisitePresetUseDefault']))
		{
			$this->setIsRequisitePresetUseDefault((bool)$importOptions['isRequisitePresetUseDefault']);
		}

		if (isset($importOptions['searchNextEntity']))
		{
			$this->setSearchNextEntity((string)$importOptions['searchNextEntity']);
		}

		if (isset($importOptions['prevEntity']))
		{
			$this->setPrevEntity((string)$importOptions['prevEntity']);
		}

		$duplicateControlTargets = [];
		foreach ($importOptions as $option => $value)
		{
			if (!str_starts_with($option, 'duplicateControlTargetsRequisite__'))
			{
				continue;
			}

			$optionNameParts = explode('__', $option);
			if (!is_array($optionNameParts) || count($optionNameParts) < 2)
			{
				continue;
			}

			$countryId = $optionNameParts[1] ?? null;
			if (!is_numeric($countryId))
			{
				continue;
			}

			if (!is_array($value))
			{
				continue;
			}

			foreach ($value as $duplicateControlTarget)
			{
				$duplicateControlTargetParts = explode('__', $duplicateControlTarget);
				if (count($duplicateControlTargetParts) < 2)
				{
					continue;
				}

				$groupId = $duplicateControlTargetParts[0] ?? null;
				if (empty($groupId))
				{
					continue;
				}

				$fieldId = $duplicateControlTargetParts[1] ?? null;
				if (empty($fieldId))
				{
					continue;
				}

				$duplicateControlTargets[$groupId][(int)$countryId][$fieldId] = true;
			}
		}

		$this->setRequisiteDupControlFieldMap($duplicateControlTargets);

		return $this;
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'isImportRequisite' => $this->isImportRequisite,
			'defaultRequisitePresetId' => $this->defaultRequisitePresetId,
			'isRequisitePresetAssociate' => $this->isRequisitePresetAssociate,
			'isRequisitePresetAssociateById' => $this->isRequisitePresetAssociateById,
			'isRequisitePresetUseDefault' => $this->isRequisitePresetUseDefault,
			'searchNextEntity' => $this->searchNextEntity,
			'prevEntity' => $this->prevEntity,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
