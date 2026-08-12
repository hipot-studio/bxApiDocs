<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Userfield;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context\ConvertContext;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use CCrmOwnerType;

final class ConvertEvent extends AbstractBuilder
{
	private int $sourceEntityTypeId = CCrmOwnerType::Undefined;

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	public function fillByContext(ConvertContext $context): self
	{
		$this->sourceEntityTypeId = $context->sourceEntityTypeId;

		return $this;
	}

	protected function buildCustomData(): array
	{
		$this->setP2('from', Dictionary::getAnalyticsEntityType($this->sourceEntityTypeId));

		return [
			'category' => Dictionary::CATEGORY_USERFIELD_OPERATIONS,
			'event' => Dictionary::EVENT_USERFIELD_CONVERT,
			'type' => ConvertContext::SOURCE_MANUAL,
		];
	}
}
