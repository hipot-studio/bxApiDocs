<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\UI\Task\Template;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateReplicateParams;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\ReplicateParamsMapper;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReplicateParamsRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;

class ReplicationHintService
{
	public function __construct(
		private readonly ReplicateParamsMapper $replicateParamsMapper,
		private readonly TemplateAccessService $templateAccessService,
		private readonly TemplateReplicateParamsRepositoryInterface $templateReplicateParamsRepository,
		private readonly LinkService $linkService,
	)
	{
	}

	public function renderForTask(
		int $taskId,
		int $forkedByTemplateId,
		int $userId,
	): ?string
	{
		return $this->renderFromTemplateReplicateParams(
			$this->getTemplateReplicateParams(
				taskId: $taskId,
				forkedByTemplateId: $forkedByTemplateId,
			),
			$userId,
		);
	}

	public function renderForTemplate(
		int $templateId,
		array $replicateParams,
		int $userId,
	): ?string
	{
		$replicateParams = $this->replicateParamsMapper->mapToValueObject($replicateParams);

		if ($replicateParams === null)
		{
			return null;
		}

		return $this->render(
			replicateParams: $replicateParams,
			templateId: $templateId,
			userId: $userId,
		);
	}

	private function getTemplateReplicateParams(int $taskId, int $forkedByTemplateId): ?TemplateReplicateParams
	{
		if ($forkedByTemplateId > 0)
		{
			return $this->templateReplicateParamsRepository->getByTemplateId($forkedByTemplateId);
		}

		return $this->templateReplicateParamsRepository->getByTaskId($taskId);
	}

	public function renderFromTemplateReplicateParams(
		?TemplateReplicateParams $templateReplicateParams,
		int $userId,
	): ?string
	{
		if (
			$templateReplicateParams?->replicateParams === null
			|| $templateReplicateParams?->templateId === null
		)
		{
			return null;
		}

		return $this->render(
			replicateParams: $templateReplicateParams->replicateParams,
			templateId: $templateReplicateParams->templateId,
			userId: $userId,
		);
	}

	private function render(
		ReplicateParams $replicateParams,
		int $templateId,
		int $userId,
	): string
	{
		if ($replicateParams->period === null)
		{
			return '';
		}

		$replicationPeriodString = $this->getReplicationPeriod($replicateParams);

		$hint = Loc::getMessage(
			code: 'TASKS_V2_REPLICATION_PERIOD_SERVICE_REGULAR_TOOLTIP_PERIOD_ONLY',
			replace: ['#PERIOD#' => $replicationPeriodString],
		);

		if ($this->templateAccessService->canRead($userId, $templateId))
		{
			$hint = Loc::getMessage(
				code: 'TASKS_V2_REPLICATION_PERIOD_SERVICE_REGULAR_TOOLTIP_TITLE',
				replace: [
					'#PERIOD#' => $replicationPeriodString,
					'#TEMPLATE_LINK#' => $this->getTemplateLink($templateId, $userId),
				],
			);
		}

		$hint = htmlspecialcharsbx('<div class="tasks-hint">' . $hint . '</div>');

		return <<<HTML
<div
	class="task-regular-icon ui-icon-set --o-repeat"
	data-tasks-hint="{$hint}"
	data-hint-no-icon
	data-hint-html
	data-hint-interactivity
></div>
HTML;
	}

	private function getReplicationPeriod(ReplicateParams $replicateParams): string
	{
		$replicateParams = $this->replicateParamsMapper->mapFromValueObject($replicateParams);

		return Template::makeReplicationPeriodString($replicateParams);
	}

	private function getTemplateLink(int $templateId, int $userId): string
	{
		$templateUrl = $this->linkService->get(new Entity\Template($templateId), $userId);

		$linkLabel = Loc::getMessage('TASKS_V2_REPLICATION_PERIOD_SERVICE_REGULAR_TOOLTIP_TEMPLATE_OPEN');

		return <<<HTML
<a href="{$templateUrl}" class="tasks-grid-tooltip-link">
	<span class="ui-icon-set --open-in-40"></span>{$linkLabel}
</a>
HTML;
	}
}
