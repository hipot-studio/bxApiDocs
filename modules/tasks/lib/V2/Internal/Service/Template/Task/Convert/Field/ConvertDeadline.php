<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\TaskBuilder;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Template\Deadline\DeadlineOffsetResolver;
use Bitrix\Tasks\V2\Internal\Service\Time\Trait\DateCalculationTrait;

class ConvertDeadline implements ConvertFieldInterface
{
	use ConfigTrait;
	use DateCalculationTrait;

	public function __invoke(Template $template, TaskBuilder $taskBuilder): void
	{
		$deadlineOffset = DeadlineOffsetResolver::fromTemplate($template);

		if ($deadlineOffset === null)
		{
			$taskBuilder->set('deadlineTs', 0);

			return;
		}

		$userId = $this->config->userId;

		if ($deadlineOffset === 0)
		{
			$taskBuilder->set('deadlineTs', $this->calculateCreationDayDeadline($userId)->getTimestamp());

			return;
		}

		$matchesWorkTime = $template->matchesWorkTime ?? false;

		$deadlineTs = $this->calculateClosestDate($deadlineOffset, $matchesWorkTime, $userId, false);

		if ($deadlineTs)
		{
			$taskBuilder->set('deadlineTs', $deadlineTs->getTimestamp());
		}
	}
}
