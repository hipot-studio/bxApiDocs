<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Robot;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

class CreateEvent extends AbstractBuilder
{
	public function __construct(
		private ?int $entityTypeId = null,
		private ?string $type = null,
	)
	{
		$this->setSection(Dictionary::getAnalyticsEntityType($this->entityTypeId));
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	protected function buildCustomData(): array
	{
		return [
			'event' => Dictionary::EVENT_ENTITY_CREATE,
			'category' => Dictionary::CATEGORY_ROBOT_OPERATIONS,
			'type' => $this->type ?? '',
		];
	}
}
