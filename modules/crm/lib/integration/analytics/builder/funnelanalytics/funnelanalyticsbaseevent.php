<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

abstract class FunnelAnalyticsBaseEvent extends AbstractBuilder
{
	abstract public function getEventName(): string;

	abstract protected function getType(): string;

	public function __construct(?string $section = null, ?string $subSection = null)
	{
		$this->setSection($section);
		$this->setSubSection($subSection);
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	protected function buildCustomData(): array
	{
		return [
			'category' => $this->getCategory(),
			'event' => $this->getEventName(),
			'type' => $this->getType(),
		];
	}

	protected function getCategory(): string
	{
		return Dictionary::CATEGORY_FUNNELS;
	}
}
