<?php

namespace Bitrix\Bizproc\Controller\Workflow;

use Bitrix\Bizproc\Api\Request\WorkflowAccessService\CheckStartWorkflowRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\PrepareParametersRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\PrepareStartParametersRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SetConstantsRequest;
use Bitrix\Bizproc\Api\Service\WorkflowAccessService;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Controller\Base;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Service\Document\DocumentsResolver;
use Bitrix\Bizproc\Internal\Service\Document\Dto\ResolvedDocumentDto;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Public\Service\Workflow\StarterService;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Dto\EventDto;
use Bitrix\Bizproc\Starter\Dto\MetaDataDto;
use Bitrix\Bizproc\Starter\Enum\Face;
use Bitrix\Main\Localization\Loc;
use CBPDocumentEventType;

class Starter extends Base
{
	private ?DocumentsResolver $documentResolver = null;

	/**
	 * @deprecated Use the `bizproc.workflow.start.list` component instead.
	 */
	public function getTemplatesAction(): ?array
	{
		if (!$this->checkBizprocFeature())
		{
			return null;
		}

		$complexDocumentType = $this->getComplexDocumentType();
		if (!$complexDocumentType)
		{
			return null;
		}

		$complexDocumentId = null;
		if ($this->hasDocumentIdInRequest())
		{
			$complexDocumentId = $this->getComplexDocumentId();
			if (!$complexDocumentId)
			{
				return null;
			}

			if (!$this->checkDocumentTypeMatchDocumentId($complexDocumentType, $complexDocumentId))
			{
				return null;
			}
		}

		return [
			'templates' => (
				\CBPDocument::getTemplatesForStart($this->getCurrentUserId(), $complexDocumentType, $complexDocumentId)
			),
		];
	}

	public function startWorkflowAction(
		int $templateId,
		?int $startDuration = null,
		?string $triggerType = null,
	): ?array
	{
		if (!$this->checkBizprocFeature())
		{
			return null;
		}

		$complexDocumentType = $this->getComplexDocumentType();
		if (!$complexDocumentType)
		{
			return null;
		}

		$complexDocumentId = $this->getComplexDocumentId();
		if (!$complexDocumentId)
		{
			return null;
		}

		if (!$this->checkDocumentTypeMatchDocumentId($complexDocumentType, $complexDocumentId))
		{
			return null;
		}

		$userId = $this->getCurrentUserId();

		$accessRequest = new CheckStartWorkflowRequest(
			userId: $userId,
			complexDocumentId: $complexDocumentId,
			parameters: [
				\CBPDocument::PARAM_TAGRET_USER => 'user_' . $userId,
				'WorkflowTemplateId' => $templateId,
			],
		);

		$accessResponse = (new WorkflowAccessService())->checkStartWorkflow($accessRequest);
		if (!$accessResponse->isSuccess())
		{
			$this->addErrors($accessResponse->getErrors());

			return null;
		}

		$templateService = new WorkflowTemplateService();
		$workflowParameters = $templateService->prepareStartParameters(
			new PrepareStartParametersRequest(
				templateId: $templateId,
				complexDocumentType: $triggerType ? Workflow::getComplexType() : $complexDocumentType,
				requestParameters: array_merge(
					$this->getRequest()->toArray(),
					$this->getRequest()->getFileList()->toArray()
				),
				targetUserId: $userId,
			)
		);

		if (!$workflowParameters->isSuccess())
		{
			$this->addErrors($workflowParameters->getErrors());

			return null;
		}

		$starter =
			$this->getStarter($templateId, $workflowParameters->getParameters(), $triggerType, $startDuration)
				->setValidateParameters(false)
		;

		$result = $starter->start();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['workflowId' => current($result->getWorkflowIds())];
	}

	private function getStarter(
		int $templateId,
		array $workflowParameters,
		?string $triggerType,
		?int $startDuration
	): \Bitrix\Bizproc\Starter\Starter
	{
		$currentUserId = $this->getCurrentUserId();

		$context = new ContextDto('bizproc', Face::WEB);
		$metaData = new MetaDataDto($startDuration >= 0 ? $startDuration : null);
		$documentId = $this->getComplexDocumentId();
		$documentType = $this->getComplexDocumentType();

		if ($triggerType)
		{
			return (new StarterService())->getStarterForManualEventScenario(
				templateIds: [$templateId],
				context: $context,
				events: [
					new EventDto(
						code: $triggerType,
						documents: [new DocumentDto($documentId, $documentType)],
						eventType: CBPDocumentEventType::Manual,
						userId: $currentUserId,
					),
				],
				userId: $currentUserId,
				parameters: $workflowParameters,
				metaData: $metaData,
			);
		}

		return (new StarterService())->getStarterForManualDocumentScenario(
			templateIds: [$templateId],
			context: $context,
			document: new DocumentDto(
				complexDocumentId: $documentId,
				complexDocumentType: $documentType,
			),
			userId: $currentUserId,
			parameters: $workflowParameters,
			metaData: $metaData,
		);
	}

	public function checkParametersAction(int $autoExecuteType): ?array
	{
		if (!$this->checkBizprocFeature())
		{
			return null;
		}

		if ($autoExecuteType < 0)
		{
			$this->addError(new Error(
				Loc::getMessage('BIZPROC_LIB_API_CONTROLLER_WORKFLOW_STARTER_ERROR_INCORRECT_AUTO_EXECUTE_TYPE') ?? ''
			));

			return null;
		}

		$documents = $this->getDocumentsForCheckParameters();
		if (empty($documents))
		{
			return null;
		}

		$accessibleDocuments = [];
		foreach ($documents as $document)
		{
			if (!$this->canUserStartWorkflowForResolvedDocument($document))
			{
				continue;
			}

			$accessibleDocuments[] = $document;
		}

		if (empty($accessibleDocuments))
		{
			$this->addError(new Error(
				Loc::getMessage('BIZPROC_LIB_API_CONTROLLER_WORKFLOW_STARTER_ERROR_ACCESS_DENIED') ?? ''
			));

			return null;
		}

		$parameters = [];
		$hasErrors = false;
		$processedTemplateIds = [];
		foreach ($accessibleDocuments as $document)
		{
			$parametersDocumentType = $document->complexDocumentType->toArray();
			foreach (\CBPWorkflowTemplateLoader::getDocumentTypeStates($parametersDocumentType, $autoExecuteType) as $template)
			{
				$templateId = (int)($template['TEMPLATE_ID'] ?? 0);
				if (
					$templateId <= 0
					|| isset($processedTemplateIds[$templateId])
					|| !is_array($template['TEMPLATE_PARAMETERS'])
					|| !$template['TEMPLATE_PARAMETERS']
				)
				{
					continue;
				}

				$processedTemplateIds[$templateId] = true;
				$parameters[$templateId] =
					$this->prepareWorkflowParameters(
						$template['TEMPLATE_PARAMETERS'],
						$parametersDocumentType,
						"bizproc{$templateId}_",
					)
				;

				if ($parameters[$templateId] === null)
				{
					$hasErrors = true;
				}
			}
		}

		if ($hasErrors)
		{
			return null;
		}

		return ['parameters' => \CBPDocument::signParameters($parameters)];
	}

	private function getDocumentsForCheckParameters(): array
	{
		$hasDocumentIdInRequest = $this->hasDocumentIdInRequest();
		$payload = $this->buildRequestDocumentsPayload($hasDocumentIdInRequest);
		$documents = $this->resolveDocumentsFromPayload($payload);
		if (empty($documents))
		{
			return [];
		}

		foreach ($documents as $resolvedDocument)
		{
			if ($hasDocumentIdInRequest && $resolvedDocument->complexDocumentId === null)
			{
				$this->addError(new Error(
					Loc::getMessage('BIZPROC_LIB_API_CONTROLLER_WORKFLOW_STARTER_ERROR_INCORRECT_DOCUMENT_ID') ?? ''
				));

				return [];
			}
		}

		return $documents;
	}

	private function canUserStartWorkflowForResolvedDocument(ResolvedDocumentDto $document): bool
	{
		$parametersDocumentType = $document->complexDocumentType->toArray();
		$complexDocumentId = $document->complexDocumentId?->toArray();
		$currentUserId = $this->getCurrentUserId();

		if (
			$complexDocumentId !== null
			&& \CBPDocument::canUserOperateDocument(
				\CBPCanUserOperateOperation::StartWorkflow,
				$currentUserId,
				$complexDocumentId,
			)
		)
		{
			return true;
		}

		return \CBPDocument::canUserOperateDocumentType(
			\CBPCanUserOperateOperation::StartWorkflow,
			$currentUserId,
			$parametersDocumentType,
		);
	}

	public function setConstantsAction(int $templateId): ?array
	{
		if (!$this->checkBizprocFeature())
		{
			return null;
		}

		$parametersDocumentType = $this->getComplexDocumentType();
		if (!$parametersDocumentType)
		{
			return null;
		}

		$request = $this->getRequest();

		$response =
			(new WorkflowTemplateService())
				->setConstants(
					new SetConstantsRequest(
						templateId: $templateId,
						requestConstants: array_merge($request->toArray(), $request->getFileList()->toArray()),
						complexDocumentType: $parametersDocumentType,
						userId: $this->getCurrentUserId(),
					)
				)
		;

		if ($response->isSuccess())
		{
			return ['success' => true];
		}

		$this->addErrors($response->getErrors());

		return null;
	}

	private function getComplexDocumentType(): ?array
	{
		$resolvedDocument = $this->resolveSingleDocumentFromPayload($this->buildRequestDocumentPayload());

		return $resolvedDocument?->complexDocumentType->toArray();
	}

	private function getComplexDocumentId(): ?array
	{
		$resolvedDocument = $this->resolveSingleDocumentFromPayload($this->buildRequestDocumentPayload(true));

		if (!$resolvedDocument)
		{
			return null;
		}

		if ($resolvedDocument->complexDocumentId === null)
		{
			$this->addError(new Error(
				Loc::getMessage('BIZPROC_LIB_API_CONTROLLER_WORKFLOW_STARTER_ERROR_INCORRECT_DOCUMENT_ID') ?? ''
			));

			return null;
		}

		return $resolvedDocument->complexDocumentId->toArray();
	}

	private function buildRequestDocumentPayload(bool $withDocumentId = false): array
	{
		$request = $this->getRequest();
		$documentType = $request->get('documentType');
		$documentId = $request->get('documentId');

		if ($documentType !== null || $documentId !== null)
		{
			$payload = ['documentType' => $documentType];
			if ($withDocumentId || $documentId !== null)
			{
				$payload['documentId'] = $documentId;
			}

			return $payload;
		}

		$payload = ['signedDocumentType' => $request->get('signedDocumentType')];
		if ($withDocumentId || $request->get('signedDocumentId') !== null)
		{
			$payload['signedDocumentId'] = $request->get('signedDocumentId');
		}

		return $payload;
	}

	private function buildRequestDocumentsPayload(bool $withDocumentId = false): array
	{
		$request = $this->getRequest();

		if (is_array($request->get('documents')))
		{
			return ['documents' => $request->get('documents')];
		}

		if (is_array($request->get('signedDocuments')))
		{
			return ['signedDocuments' => $request->get('signedDocuments')];
		}

		return $this->buildRequestDocumentPayload($withDocumentId);
	}

	private function hasDocumentIdInRequest(): bool
	{
		$request = $this->getRequest();

		if ($request->get('documentId') !== null || $request->get('signedDocumentId') !== null)
		{
			return true;
		}

		foreach (['documents', 'signedDocuments'] as $requestKey)
		{
			$documents = $request->get($requestKey);
			if (!is_array($documents))
			{
				continue;
			}

			foreach ($documents as $document)
			{
				if (
					is_array($document)
					&& (
						array_key_exists('documentId', $document)
						|| array_key_exists('signedDocumentId', $document)
					)
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	private function checkDocumentTypeMatchDocumentId(array $parametersDocumentType, array $parametersDocumentId): bool
	{
		return $this->resolveSingleDocumentFromPayload([
			'documentType' => $parametersDocumentType,
			'documentId' => $parametersDocumentId,
		]) !== null;
	}

	private function getDocumentResolver(): DocumentsResolver
	{
		return $this->documentResolver ??= new DocumentsResolver();
	}

	private function resolveSingleDocumentFromPayload(array $payload): ?ResolvedDocumentDto
	{
		$documents = $this->resolveDocumentsFromPayload($payload);
		if (empty($documents))
		{
			return null;
		}

		return $documents[0] ?? null;
	}

	private function resolveDocumentsFromPayload(array $payload): array
	{
		$result = $this->getDocumentResolver()->resolveFromPayload($payload);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getDocuments()?->documents ?? [];
	}

	private function checkBizprocFeature(): bool
	{
		if (!\CBPRuntime::isFeatureEnabled())
		{
			$this->addError(new Error(
				Loc::getMessage('BIZPROC_LIB_API_CONTROLLER_WORKFLOW_STARTER_ERROR_BIZPROC_FEATURE_DISABLED') ?? '')
			);

			return false;
		}

		return true;
	}

	private function getCurrentUserId(): int
	{
		return (int)($this->getCurrentUser()?->getId());
	}

	private function prepareWorkflowParameters(
		array $templateParameters,
		array $parametersDocumentType,
		string $keyPrefix = '',
	): ?array
	{
		$request = $this->getRequest();
		$allRequestParameters = array_merge($request->toArray(), $request->getFileList()->toArray());

		$requestParameters = [];
		foreach($templateParameters as $key => $property)
		{
			$searchKey = $keyPrefix . $key;
			$requestParameters[$key] = $allRequestParameters[$searchKey] ?? null;
		}

		$parameters = (new WorkflowTemplateService())
			->prepareParameters(
				new PrepareParametersRequest(
					templateParameters: $templateParameters,
					requestParameters: $requestParameters,
					complexDocumentType: $parametersDocumentType,
				)
			)
		;

		if ($parameters->isSuccess())
		{
			return $parameters->getParameters();
		}

		$this->addErrors($parameters->getErrors());

		return null;
	}
}
