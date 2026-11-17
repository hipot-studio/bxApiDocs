<?php

namespace Bitrix\Tasks\Replication\Template\Repetition\Conversion\Converters;

use Bitrix\Tasks\Replication\Template\Repetition\Conversion\ConverterInterface;
use Bitrix\Tasks\Replication\RepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Deadline\DeadlineOffsetResolver;
use Bitrix\Tasks\V2\Internal\Service\Time\Trait\DateCalculationTrait;

abstract class AbstractDateTimeConverter implements ConverterInterface
{
	use DateCalculationTrait;

	public function convert(RepositoryInterface $repository): array
	{
		$taskFields = [];

		$template = $repository->getEntity();
		if (!$template)
		{
			return $taskFields;
		}

		$deadlineOffset = DeadlineOffsetResolver::fromRawParams(
			$template->getReplicateParams(),
			$template->getDeadlineAfter(),
		);

		if ($deadlineOffset === 0)
		{
			$taskFields['DEADLINE'] = $this->calculateCreationDayDeadline($template->getResponsibleId());
		}
		elseif ($deadlineOffset !== null)
		{
			$taskFields['DEADLINE'] = $this->calculateClosestDate(
				$deadlineOffset,
				$template->getMatchWorkTime(),
				$template->getResponsibleId(),
				false,
			);
		}

		if ($template->getStartDatePlanAfter() > 0)
		{
			$taskFields['START_DATE_PLAN'] = $this->calculateClosestDate(
				$template->getStartDatePlanAfter(),
				$template->getMatchWorkTime(),
				$template->getResponsibleId(),
				false,
			);
		}

		if ($template->getEndDatePlanAfter() > 0)
		{
			$taskFields['END_DATE_PLAN'] = $this->calculateClosestDate(
				$template->getEndDatePlanAfter(),
				$template->getMatchWorkTime(),
				$template->getResponsibleId(),
				false,
			);
		}

		return $taskFields;
	}

	abstract public function getTemplateFieldName(): string;
}