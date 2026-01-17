<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockDescriptionService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class GetBlockSettingsTool extends BizprocDesignerTool
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
		$this->blockDescriptionService = $blockDescriptionService ?? new BlockDescriptionService();
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
	}

	public function getName(): string
	{
		return 'get_block_settings';
	}

	public function getDescription(): string
	{
		return 'Returns block description and list of block settings for selected block, additional description of setting type can be available in typesDescription section, if present';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'blockType' => [
					'type' => 'string',
					'description' => 'Block type',
				],
			],
			'required' => ['blockType'],
			'additionalProperties' => false,
		];
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

		$blockType = (string)($args['blockType'] ?? '');

		$this->tracedLogger->debug('Running tool {toolName} for user {userId} and {blockType}', [
			'toolName' => $this->getName(),
			'userId' => $userId,
			'blockType' => $blockType,
		]);

		$result = $this->blockDescriptionService->getBlockSettings($documentType, $blockType);
		if ($result instanceof BlockSettingsResult)
		{
			return Json::encode($result->blockDetail->toArray());
		}

		return 'Error: '. implode(', ', $result->getErrorMessages());
	}

}