<?php

namespace Bitrix\Tasks\Replication\Template\Repetition;

use Bitrix\Tasks\Replication\Template\Common\TemplateTaskProducer;
use Bitrix\Tasks\Replication\Template\Repetition\Service\TemplateHistoryService;
use Bitrix\Tasks\V2\Internal\Entity\Analytics;
use Bitrix\Tasks\V2\Internal\Entity\Analytics\AnalyticsData;

class RegularTemplateTaskProducer extends TemplateTaskProducer
{
	protected function initHistoryService(): void
	{
		$this->templateHistoryService = new TemplateHistoryService($this->repository);
	}

	protected function getAnalyticsData(): ?AnalyticsData
	{
		return new AnalyticsData(
			category: Analytics\Category::TaskOperations,
			section: Analytics\Section::Tasks,
			subSection: Analytics\SubSection::Replication,
			element: Analytics\Element::Auto,
		);
	}
}
