<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentFieldService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\OperationAuthorizationGuard;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserAdminResolver;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Web\Json;

class SearchDocumentFieldsTool extends BizprocDesignerTool
{
	private readonly DocumentFieldService $documentFieldService;
	private readonly LastWorkflowService $lastWorkflowService;
	private readonly OperationAuthorizationGuard $operationAuthorizationGuard;

	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
		?DocumentFieldService $documentFieldService = null,
		?LastWorkflowService $lastWorkflowService = null,
		?OperationAuthorizationGuard $operationAuthorizationGuard = null,
	)
	{
		parent::__construct($tracedLogger, $availabilityService);
		$this->documentFieldService = $documentFieldService ?? new DocumentFieldService();
		$this->lastWorkflowService = $lastWorkflowService ?? Container::getAiAssistantLastWorkflowService();
		$this->operationAuthorizationGuard = $operationAuthorizationGuard ?? new OperationAuthorizationGuard();
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
		$identifier = $this->lastWorkflowService->getUserLastWorkflowTemplateIdentifier($userId);
		$documentType = $identifier?->documentDescription;
		if ($documentType === null)
		{
			return 'Error: No user saved document type';
		}

		$verdict = $this->operationAuthorizationGuard->authorize(
			$userId,
			$documentType,
			(int)($identifier->templateId ?? 0),
			UserAdminResolver::isAdmin($userId),
		);
		if (!$verdict->allowed)
		{
			return 'Error: User access denied for this document type';
		}

		$searchField = $args['searchField'] ?? null;

		$fields = $this->documentFieldService->getFields($documentType, $searchField);

		return Json::encode($fields->toArray());
	}

}