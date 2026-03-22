<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Userfield;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context\CreateContext;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CreateEvent extends AbstractBuilder
{
	private string $creationContext = Dictionary::SUB_SECTION_USERFIELD_DEFAULT;

	public function setCreationContext(string $creationContext): self
	{
		$this->creationContext = $creationContext;

		return $this;
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	public function fillByContext(CreateContext $context): self
	{
		$this->setCreationContext($context->createFrom);

		return $this;
	}

	protected function buildCustomData(): array
	{
		$this->setSection($this->creationContext);

		return [
			'category' => Dictionary::CATEGORY_ENTITY_OPERATIONS,
			'event' => Dictionary::EVENT_ENTITY_CREATE,
			'type' => Dictionary::TYPE_USERFIELD,
		];
	}
}
