<?php

namespace Bitrix\Tasks\Replication\Template\Repetition\Conversion\Converters;

use Bitrix\Tasks\Replication\Template\Repetition\Conversion\ConverterInterface;
use Bitrix\Tasks\Replication\RepositoryInterface;
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

		if ($template->getDeadlineAfter() > 0)
		{
			$taskFields['DEADLINE'] = $this->calculateClosestDate(
				$template->getDeadlineAfter(),
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