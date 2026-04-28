<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Import;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlBehavior;
use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Result;
use CCrmOwnerType;

final class EditEvent extends AbstractBuilder
{
	private ?int $entityTypeId = null;
	private ?Origin $origin = null;

	private ?DuplicateControlBehavior $duplicateControlBehavior = null;

	public function setEntityTypeId(int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	public function setOrigin(?Origin $origin): self
	{
		$this->origin = $origin;

		return $this;
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	public function setIsDefaultOpened(): self
	{
		$this->setElement('import_default_opened');

		return $this;
	}

	public function setIsImportRequisite(): self
	{
		$this->setElement('import_requisite');

		return $this;
	}

	public function setIsCreateButton(): self
	{
		$this->setElement('create_button');

		return $this;
	}

	public function setIsDeleteButton(): self
	{
		$this->setElement('delete_button');

		return $this;
	}

	public function setDuplicateControlBehavior(?DuplicateControlBehavior $duplicateControlBehavior): self
	{
		$this->duplicateControlBehavior = $duplicateControlBehavior;

		return $this;
	}

	protected function buildCustomData(): array
	{
		if ($this->origin !== null)
		{
			$this->setSubSection($this->origin->value);
		}

		if ($this->duplicateControlBehavior !== null)
		{
			$this->setP2WithValueNormalization('importDuplicateControlType', $this->duplicateControlBehavior->value);
		}

		return [
			'event' => Dictionary::EVENT_IMPORT_EDIT,
			'category' => Dictionary::CATEGORY_IMPORT_OPERATIONS,
			'type' => Dictionary::getAnalyticsEntityType($this->entityTypeId),
		];
	}

	protected function customValidate(): Result
	{
		if (!CCrmOwnerType::IsDefined($this->entityTypeId))
		{
			return Result::fail(ErrorCode::getRequiredArgumentMissingError('entityTypeId'));
		}

		return Result::success();
	}
}
