<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Services\Scope\TasksFlowAccessibilityService;
use Bitrix\BIConnector\Internal\Services\Scope\WorkflowTemplateAccessibilityService;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;

final class ScopeAccessibleValueProvider
{
	private readonly WorkflowTemplateAccessibilityService $workflowTemplateService;
	private readonly TasksFlowAccessibilityService $tasksFlowService;

	public function __construct(
		?WorkflowTemplateAccessibilityService $workflowTemplateService = null,
		?TasksFlowAccessibilityService $tasksFlowService = null,
	)
	{
		$this->workflowTemplateService = $workflowTemplateService ?? new WorkflowTemplateAccessibilityService();
		$this->tasksFlowService = $tasksFlowService ?? new TasksFlowAccessibilityService();
	}

	/**
	 * @param int $limit Max rows; 0 means unbounded (e.g. the UI native filter).
	 *
	 * @return array<array{id:int, name:string}>
	 */
	public function getList(
		Parameter $code,
		int $userId,
		?string $search = null,
		int $limit = 200,
		int $offset = 0,
	): array
	{
		return match ($code)
		{
			Parameter::WorkflowTemplateId => $this->workflowTemplateService
				->findAccessibleForUser($userId, $search, $limit, $offset),
			Parameter::TasksFlowsFlowId => $this->tasksFlowService
				->findAccessibleForUser($userId, $search, $limit, $offset),
			default => [],
		};
	}

	public function isAccessible(Parameter $code, int $userId, int $valueId): bool
	{
		return match ($code)
		{
			Parameter::WorkflowTemplateId => $this->workflowTemplateService->isAccessibleForUser($userId, $valueId),
			Parameter::TasksFlowsFlowId => $this->tasksFlowService->isAccessibleForUser($userId, $valueId),
			default => false,
		};
	}
}
