<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentFieldService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class SearchDocumentFieldsTool extends BizprocDesignerTool
{
	private readonly DocumentAccessService $documentAccessService;
	private readonly DocumentFieldService $documentFieldService;
	private readonly LastWorkflowService $lastWorkflowService;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?DocumentAccessService $documentAccessService = null,
		?DocumentFieldService $documentFieldService = null,
		?LastWorkflowService $lastWorkflowService = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
		$this->documentFieldService = $documentFieldService ?? new DocumentFieldService();
		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
	}

	public function getName(): string
	{
		return 'search_document_fields';
	}

	public function getDescription(): string
	{
		return 'Search document fields for selected document type by field name or description';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'searchField' => [
					'description' => 'Search field name or description to filter fields for selected document type',
					'type' => 'string',
				],
			],
			'required' => ['searchField'],
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

		$searchField = $args['searchField'] ?? null;

		$fields = $this->documentFieldService->getFields($documentType, $searchField);

		return Json::encode($fields->toArray());
	}

}