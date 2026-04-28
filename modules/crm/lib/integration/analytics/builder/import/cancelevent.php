<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Import;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Result;
use CCrmOwnerType;

final class CancelEvent extends AbstractBuilder
{
	private ?int $entityTypeId = null;
	private ?string $step = null;
	private ?Origin $origin = null;

	public const STEP_CONFIGURE_IMPORT_SETTINGS = 'configureImportSettings';
	public const STEP_CONFIGURE_FIELD_RATIO = 'configureFieldRatio';
	public const STEP_CONFIGURE_DUPLICATE_CONTROL = 'configureDuplicateControl';
	public const STEP_IMPORT = 'Import';

	private const AVAILABLE_STEPS = [
		self::STEP_CONFIGURE_IMPORT_SETTINGS,
		self::STEP_CONFIGURE_FIELD_RATIO,
		self::STEP_CONFIGURE_DUPLICATE_CONTROL,
		self::STEP_IMPORT,
	];

	public function setEntityTypeId(?int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	public function setStep(?string $step): self
	{
		$this->step = $step;

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

	protected function buildCustomData(): array
	{
		if ($this->step !== null)
		{
			$this->setP2WithValueNormalization('step', $this->step);
		}

		if ($this->entityTypeId === CCrmOwnerType::Contact && $this->origin !== null)
		{
			$this->setSubSection($this->origin->value);
		}

		return [
			'event' => Dictionary::EVENT_IMPORT_CANCEL,
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

		if (!in_array($this->step, self::AVAILABLE_STEPS, true))
		{
			return Result::fail(ErrorCode::getRequiredArgumentMissingError('step'));
		}

		if ($this->entityTypeId === CCrmOwnerType::Contact && $this->origin === null)
		{
			return Result::fail(ErrorCode::getRequiredArgumentMissingError('origin'));
		}

		return Result::success();
	}
}
