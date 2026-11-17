<?php

namespace Bitrix\Tasks\Replication\Template\Repetition;

use Bitrix\Tasks\Replication\Template\Common\TemplateTaskProducer;
use Bitrix\Tasks\Replication\Template\Repetition\Service\TemplateHistoryService;
use Bitrix\Tasks\Replication\Template\Repetition\Time\Service\ExecutionService;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Analytics;
use Bitrix\Tasks\V2\Internal\Entity\Analytics\AnalyticsData;

class RegularTemplateTaskProducer extends TemplateTaskProducer
{
	protected function initHistoryService(): void
	{
		$this->templateHistoryService = new TemplateHistoryService($this->repository);
	}

	/**
	 * For the deadline strategy the instance DEADLINE is the on-pattern deadline computed by
	 * the time service (ALG-01), not a value from HTTP/UI. Placed in the regular producer (not
	 * the base one shared with TemplateTaskReplicator) so only regular replication stamps it.
	 * schedule / after_completion keep the existing DEADLINE_OFFSET behavior untouched.
	 */
	protected function overrideCreatedDate(): static
	{
		parent::overrideCreatedDate();

		$deadlineTS = (new ExecutionService($this->repository))->getTemplateCurrentDeadline();
		if ($deadlineTS !== null)
		{
			$this->fields['DEADLINE'] = new DateTime(UI::formatDateTime($deadlineTS));
		}

		return $this;
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
