<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class GetWorkflowTool extends BizprocDesignerTool
{
	private readonly LastWorkflowService $lastWorkflowService;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?LastWorkflowService $lastWorkflowService = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);

		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
	}

	public function getName(): string
	{
		return 'get_workflow_template';
	}

	public function getDescription(): string
	{
		return 'Retrieve existed user workflow template';
	}

	public function getInputSchema(): array
	{
		return [];
	}

	protected function execute(int $userId, ...$args): string
	{
		// @TODO refactor with id of element, when agent has it
		$lastAgentTemplate = $this->lastWorkflowService->getAgentTemplate($userId);
		if ($lastAgentTemplate === null)
		{
			return 'Error: no workflow template found for user';
		}

		 return Json::encode($lastAgentTemplate->toArray());
	}
}