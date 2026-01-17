<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockDescriptionService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class GetBlockTool extends BizprocDesignerTool
{
	private readonly BlockDescriptionService $blockDescriptionService;
	private readonly DocumentAccessService $documentAccessService;
	private readonly LastWorkflowService $lastWorkflowService;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?BlockDescriptionService $blockDescriptionService = null,
		?DocumentAccessService $documentAccessService = null,
		?LastWorkflowService $lastWorkflowService = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);
		$this->blockDescriptionService =  $blockDescriptionService ?? new BlockDescriptionService();
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
	}

	public function getName(): string
	{
		return 'get_block';
	}

	public function getDescription(): string
	{
		return 'Returns list of blocks for selected document type';
	}

	public function getInputSchema(): array
	{
		return [];
	}

	public function execute(int $userId, ...$args): string
	{
		$documentType = $this->lastWorkflowService->getUserLastWorkflowTemplateIdentifier($userId)?->documentDescription;
		if ($documentType === null)
		{
			return 'Error: No user saved document type';
		}

		if (!$this->documentAccessService->canCreate($userId, $documentType))
		{
			return 'Error: User access denied for this document type';
		}

		$result = $this->blockDescriptionService->getBlocksWithDescription($documentType);

		if ($result instanceof BlockDescriptionResult)
		{
			return Json::encode($result->blocks->toArray());
		}

		return 'Error: '. implode(', ', $result->getErrorMessages());
	}

}