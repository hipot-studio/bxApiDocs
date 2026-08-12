<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Userfield;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context\CreateContext;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CreateEvent extends AbstractBuilder
{
	private string $source = CreateContext::SOURCE_DEFAULT;

	public function setSource(string $source): self
	{
		$this->source = $source;

		return $this;
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	public function fillByContext(CreateContext $createContext): self
	{
		$this->setSource($createContext->source);

		return $this;
	}

	protected function buildCustomData(): array
	{
		$this->setSection(Dictionary::UNKNOWN);

		return [
			'category' => Dictionary::CATEGORY_USERFIELD_OPERATIONS,
			'event' => Dictionary::EVENT_USERFIELD_CREATE,
			'type' => $this->source,
		];
	}
}
