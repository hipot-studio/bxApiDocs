<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Automation\AutomatedSolution;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Main\Type\ArrayHelper;

final class CreateEvent extends AbstractBuilder
{
	private ?int $id = null;
	private array $typeIds = [];
	private ?string $source = null;
	private ?string $applicationCode = null;

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function setTypeIds(array $typeIds): self
	{
		ArrayHelper::normalizeArrayValuesByInt($typeIds);

		$this->typeIds = $typeIds;

		return $this;
	}

	public function setSource(?string $source): self
	{
		$this->source = $source;

		return $this;
	}

	public function setApplicationCode(?string $applicationCode): self
	{
		$this->applicationCode = $applicationCode;

		return $this;
	}

	protected function buildCustomData(): array
	{
		if ($this->id > 0)
		{
			$this->setP2('id', $this->id);
		}
		if ($this->typeIds)
		{
			$this->setP4('typeIds', implode(',', $this->typeIds));
		}
		if (
			$this->source === Dictionary::SOURCE_MARKETPLACE
			&& $this->applicationCode !== null
			&& $this->applicationCode !== ''
		)
		{
			$this->setP3('marketId', str_replace('_', '-', $this->applicationCode));
		}
		if (in_array($this->source, [Dictionary::SOURCE_MARKETPLACE, Dictionary::SOURCE_LOCAL], true))
		{
			$this->setP5(Dictionary::P5_KEY_SOURCE, $this->source);
		}

		return [
			'category' => Dictionary::CATEGORY_AUTOMATION_OPERATIONS,
			'event' => Dictionary::EVENT_AUTOMATION_CREATE,
			'type' => Dictionary::TYPE_AUTOMATED_SOLUTION,
		];
	}
}
