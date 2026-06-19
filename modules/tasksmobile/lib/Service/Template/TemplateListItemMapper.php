<?php

namespace Bitrix\TasksMobile\Service\Template;

use Bitrix\Tasks\V2\Internal\Entity\Priority as TemplatePriority;
use Bitrix\TasksMobile\Enum\TaskPriority;

final class TemplateListItemMapper
{
	/**
	 * @param \Bitrix\Tasks\V2\Internal\Entity\Template $template
	 */
	public function map(\Bitrix\Tasks\V2\Internal\Entity\Template $template): array
	{
		$templateId = $template->id ?? 0;
		$responsibleId = $this->getResponsibleId($template);
		$priorityId = $this->getPriorityId($template);

		return [
			'id' => $templateId,
			'name' => $template->title ?? '',
			'responsibleId' => $responsibleId,
			'priority' => $priorityId,
			'deadlineAfter' => $template->deadlineAfter ?? 0,
			'isMuted' => false,
			'isRepeatable' => $template->replicate ?? false,
			'checklist' => null,
			'status' => 0,
			'deadline' => 0,
			'activityDate' => 0,
			'allowTimeTracking' => $template->allowsTimeTracking ?? false,
			'timeElapsed' => 0,
			'timeEstimate' => $template->estimatedTime ?? 0,
			'isTimerRunningForCurrentUser' => false,
			'isCreationErrorExist' => false,
		];
	}

	/**
	 * @param \Bitrix\Tasks\V2\Internal\Entity\Template $template
	 */
	public function getResponsibleId(\Bitrix\Tasks\V2\Internal\Entity\Template $template): int
	{
		if ($template->responsibleCollection)
		{
			return (int)($template->responsibleCollection?->getFirstEntity()?->getId() ?? 0);
		}

		return 0;
	}

	/**
	 * @param \Bitrix\Tasks\V2\Internal\Entity\Template $template
	 */
	private function getPriorityId(\Bitrix\Tasks\V2\Internal\Entity\Template $template): int
	{
		$priority = $template->priority;

		return ($priority === TemplatePriority::High) ? TaskPriority::High->value : TaskPriority::Normal->value;
	}
}
