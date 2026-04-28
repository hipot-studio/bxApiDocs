<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Import;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Result;
use CCrmOwnerType;

final class ViewEvent extends AbstractBuilder
{
	private ?int $entityTypeId = null;
	private ?Origin $origin = null;
	private bool $isMigration = false;

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

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

	public function setIsMigration(bool $isMigration): self
	{
		$this->isMigration = $isMigration;

		return $this;
	}

	protected function customValidate(): Result
	{
		if (!CCrmOwnerType::IsDefined($this->entityTypeId))
		{
			return Result::fail(ErrorCode::getRequiredArgumentMissingError('entityTypeId'));
		}

		if (
			$this->entityTypeId === CCrmOwnerType::Contact
			&& $this->origin === null
			&& !$this->isMigration
		)
		{
			return Result::fail(ErrorCode::getRequiredArgumentMissingError('origin'));
		}

		return Result::success();
	}

	protected function buildCustomData(): array
	{
		if ($this->origin !== null && $this->entityTypeId === CCrmOwnerType::Contact)
		{
			$this->setSubSection($this->origin->value);
		}

		if ($this->isMigration)
		{
			$this->setElement(Dictionary::ELEMENT_MIGRATION_BUTTON);
		}

		return [
			'event' => Dictionary::EVENT_IMPORT_VIEW,
			'category' => Dictionary::CATEGORY_IMPORT_OPERATIONS,
			'type' => Dictionary::getAnalyticsEntityType($this->entityTypeId),
		];
	}
}
