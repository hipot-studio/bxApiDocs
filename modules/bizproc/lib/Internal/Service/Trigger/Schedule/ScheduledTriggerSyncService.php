<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\WorkflowTemplateRepository;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;

final class ScheduledTriggerSyncService
{
	public function __construct(
		private readonly ScheduleSyncService $scheduleSyncService,
		private readonly WorkflowTemplateRepository $workflowTemplateRepository,
	)
	{
	}

	/**
	 * Synchronizes scheduled triggers for the given workflow template.
	 *
	 * @param int $templateId
	 * @param array|null $triggers
	 * @param bool|null $active
	 *
	 * @return void
	 */
	public function syncByTemplate(int $templateId, ?array $triggers = null, ?bool $active = null): void
	{
		if ($templateId <= 0)
		{
			return;
		}

		$active = $active ?? $this->workflowTemplateRepository->isTemplateActive($templateId);
		$triggers = $triggers ?? $this->getTemplateTriggers($templateId);

		$this->scheduleSyncService->syncByTemplate($templateId, $triggers, $active);
	}

	private function getTemplateTriggers(int $templateId): array
	{
		$triggers = WorkflowTemplateTriggerTable::query()
			->setSelect(['TRIGGER_NAME', 'TRIGGER_TYPE', 'APPLY_RULES'])
			->where('TEMPLATE_ID', $templateId)
			->fetchAll()
		;
		if (!$triggers)
		{
			return [];
		}

		return $triggers;
	}
}
