<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\IM\Notification\Analytics;

use Bitrix\Tasks\Helper\Analytics;

final class AnalyticsData
{
	private string $section = Analytics::SECTION['chat'];
	private string $element = Analytics::ELEMENT['title_click'];

	public function getSection(): string
	{
		return $this->section;
	}

	public function setSection(string $section): self
	{
		$this->section = $section;

		return $this;
	}
	
	public function getElement(): string
	{
		return $this->element;
	}

	public function setElement(string $element): self
	{
		$this->element = $element;

		return $this;
	}
}
