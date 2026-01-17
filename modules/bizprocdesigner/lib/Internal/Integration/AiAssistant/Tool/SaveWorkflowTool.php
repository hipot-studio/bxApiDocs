<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftCreatorService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\SaveWorkflowValidator;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class SaveWorkflowTool extends BizprocDesignerTool
{
	private readonly DocumentAccessService $documentAccessService;
	private readonly AiAssistantDraftCreatorService $aiAssistantDraftCreatorService;
	private readonly AiAssistantDraftConverterService $aiAssistantDraftConverterService;
	private readonly LastWorkflowService $lastWorkflowService;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?DocumentAccessService $documentAccessService = null,
		?AiAssistantDraftCreatorService $aiAssistantDraftCreatorService = null,
		?AiAssistantDraftConverterService $aiAssistantDraftConverterService = null,
		?LastWorkflowService $lastWorkflowService = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
		$this->aiAssistantDraftCreatorService = $aiAssistantDraftCreatorService ?? Container::getAiAssistantDraftCreatorService();
		$this->aiAssistantDraftConverterService = $aiAssistantDraftConverterService
			?? Container::getAiAssistantDraftConverterService()
		;
		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
	}

	public function getName(): string
	{
		return 'save_workflow_template';
	}

	public function getDescription(): string
	{
		return 'Represent workflow template for user';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'blocks' => [
					'description' => 'Array of block descriptions',
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'type' => [
								'description' => 'Type of block, one of described types',
								'type' => 'string',
							],
							'title' => [
								'description' => 'Human-readable title of block and role in current workflow, no BB-code or other tags available, only plain-text',
								'type' => 'string',
							],
							'settings' => [
								'type' => 'array',
								'description' => 'Settings of block different from type to type',
								'items' => [
									'type' => 'object',
									'properties' => [
										'name' => [
											'description' => 'Setting name from get_block_settings tool response, unique in array elements',
											'type' => 'string',
										],
										'value' => [
											'description' => 'Value of the block setting for the current action in accordance with the purpose and role in the business process and user desires, can be fixed value of described type or if setting allows multiple values, then array of values of described type',
											'anyOf' => [
												['type' => 'string'],
												['type' => 'array', 'items' => ['type' => 'string']],
												['type' => 'object'],
											],
										],
									],
									'required' => ['name', 'value'],
									'additionalProperties' => false,
								],
							],
							'id' => [
								'type' => 'string',
								'description' => 'Unique identifier of block, should be used in connections, use existed block identifier from get_workflow_template tool or use unique numeric identifier for new block'
							],
						],
						'required' => ['type', 'title', 'id', 'settings'],
						'additionalProperties' => false,
					],
				],
				'connections' => [
					'description' => 'Array of connections of blocks',
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'sourceBlockId' => [
								'description' => 'Unique identifier of source block',
								'type' => 'string',
							],
							'destinationBlockId' => [
								'description' => 'Unique identifier of destination block',
								'type' => 'string',
							],
						],
						'required' => ['sourceBlockId', 'destinationBlockId'],
						'additionalProperties' => false,
					],
				],
			],
			'required' => ['blocks', 'connections'],
			'additionalProperties' => false,
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$templateIdentifier = $this->lastWorkflowService->getUserLastWorkflowTemplateIdentifier($userId);
		$documentType = $templateIdentifier?->documentDescription;

		if ($documentType === null)
		{
			return 'Error: No user saved document type';
		}

		if (!$this->documentAccessService->canCreate($userId, $documentType))
		{
			return 'Error: User access denied for this document type';
		}

		$this->logUsage($userId, (array)$args);

		$validator = new SaveWorkflowValidator(
			input: $args,
			documentType: $documentType,
		);

		$validateResult = $validator->validate();
		if (!$validateResult instanceof AgentWorkflowValidationResult)
		{
			if (empty($validateResult->getErrors()))
			{
				return 'Error: validation logic error';
			}

			return 'Error: ' . implode(', ', $validateResult->getErrorMessages());
		}

		$draft = $this->aiAssistantDraftConverterService->covertFromAgentInput(
			draftId: $templateIdentifier->draftId ?? 0,
			templateId: $templateIdentifier->templateId ?? 0,
			userId: $userId,
			blocks: $validateResult->blocks,
			connections: $validateResult->connections,
		);

		$isSuccess = $this->aiAssistantDraftCreatorService->pushDraft($draft);

		return $isSuccess ?  'Workflow saved successfully' : 'Error saving workflow';
	}

	private function logUsage(int $userId, array $args): void
	{
		$this->tracedLogger->debug('Running tool {toolName} for user {userId}, args: {args}', [
			'toolName' => $this->getName(),
			'userId' => $userId,
			'args' => Json::encode($args, Json::DEFAULT_OPTIONS | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		]);
	}
}