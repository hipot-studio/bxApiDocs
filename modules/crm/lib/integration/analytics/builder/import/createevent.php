<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Import;

use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CreateEvent extends AbstractBuilder
{
	private ?int $entityTypeId = null;
	private ?Origin $origin = null;

	private ?int $successCount = null;
	private ?int $errorCount = null;
	private ?int $duplicateCount = null;

	public function setEntityTypeId(?int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	public function setOrigin(?Origin $origin): self
	{
		$this->origin = $origin;

		return $this;
	}

	public function setIsDoneButton(): self
	{
		$this->setElement('crm_import_done');

		return $this;
	}

	public function setIsAgainButton(): self
	{
		$this->setElement('crm_import_again');

		return $this;
	}

	public function setSuccessCount(int $successCount): self
	{
		$this->successCount = $successCount;

		return $this;
	}

	public function setErrorCount(int $errorCount): self
	{
		$this->errorCount = $errorCount;

		return $this;
	}

	public function setDuplicateCount(int $duplicateCount): self
	{
		$this->duplicateCount = $duplicateCount;

		return $this;
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	protected function buildCustomData(): array
	{
		if ($this->successCount !== null && $this->successCount > 0)
		{
			$this->setP2WithValueNormalization('successCount', $this->successCount);
		}

		if ($this->errorCount !== null && $this->errorCount > 0)
		{
			$this->setP3WithValueNormalization('errorCount', $this->errorCount);
		}

		if ($this->duplicateCount !== null && $this->duplicateCount > 0)
		{
			$this->setP4WithValueNormalization('duplicateCount', $this->duplicateCount);
		}

		if ($this->origin !== null)
		{
			$this->setSubSection($this->origin->value);
		}

		return [
			'event' => Dictionary::EVENT_IMPORT_CREATE,
			'category' => Dictionary::CATEGORY_IMPORT_OPERATIONS,
			'type' => Dictionary::getAnalyticsEntityType($this->entityTypeId),
		];
	}
}
