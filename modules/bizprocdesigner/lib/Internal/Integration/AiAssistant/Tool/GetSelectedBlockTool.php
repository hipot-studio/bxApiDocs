<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserBlockService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class GetSelectedBlockTool extends BizprocDesignerTool
{
	private readonly LastWorkflowService $lastWorkflowService;
	private readonly UserBlockService $userBlockService;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?LastWorkflowService $lastWorkflowService = null,
		?UserBlockService $userBlockService = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);

		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
		$this->userBlockService = $userBlockService ?? Container::getAiAssistantUserBlockService();
	}

	public function getName(): string
	{
		return 'get_selected_block';
	}

	public function getDescription(): string
	{
		return 'Retrieve user selected block in json format';
	}

	public function getInputSchema(): array
	{
		return [];
	}

	protected function execute(int $userId, ...$args): string
	{
		$blockId = $this->userBlockService->get($userId);
		if (empty($blockId))
		{
			return 'Error: no selected block found for user';
		}

		$lastAgentTemplate = $this->lastWorkflowService->getAgentTemplate($userId);
		if ($lastAgentTemplate === null)
		{
			return 'Error: no workflow template found for user';
		}

		$block = $lastAgentTemplate->blocks->getById($blockId);
		if ($block === null)
		{
			return 'Error: no selected block found for user';
		}

		return Json::encode($block->toArray());
	}
}