<?php

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Api\Request;
use Bitrix\Bizproc\Api\Request\WorkflowAccessService\CheckStartWorkflowRequest;
use Bitrix\Bizproc\Api\Request\WorkflowService\StartWorkflowRequest;
use Bitrix\Bizproc\Api\Request\WorkflowService\TerminateWorkflowRequest;
use Bitrix\Bizproc\Api\Request\WorkflowService\TerminateByTemplateRequest;
use Bitrix\Bizproc\Api\Response\Error;
use Bitrix\Bizproc\Api\Response\WorkflowService\StartWorkflowResponse;
use Bitrix\Bizproc\Api\Response\WorkflowService\TerminateWorkflowResponse;
use Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata;
use Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;

class WorkflowService
{
	private const PREFIX_LOC_ID = 'BIZPROC_LIB_API_WORKFLOW_SERVICE_';
	private const UNKNOWN_CREATE_WORKFLOW_ERROR = 'UNKNOWN_CREATE_WORKFLOW_ERROR';

	private WorkflowAccessService $accessService;

	public function __construct(?WorkflowAccessService $accessService = null)
	{
		$this->accessService = $accessService ?? new WorkflowAccessService();
	}

	public function startWorkflow(StartWorkflowRequest $request): StartWorkflowResponse
	{
		$response = new StartWorkflowResponse();

		if ($request->checkAccess)
		{
			$accessRequest = new CheckStartWorkflowRequest(
				userId: $request->userId,
				complexDocumentId: $request->complexDocumentId,
				parameters: [
					\CBPDocument::PARAM_TAGRET_USER => 'user_' . $request->targetUserId,
					'DocumentCategoryId' => $request->documentCategoryId,
					'WorkflowTemplateId' => $request->templateId,
				],
			);

			$accessResponse = $this->accessService->checkStartWorkflow($accessRequest);
			if (!$accessResponse->isSuccess())
			{
				$response->addErrors($accessResponse->getErrors());

				return $response;
			}
		}

		if (isset($request->startDuration) && $request->startDuration < 0)
		{
			throw new ArgumentException('Start duration must be non negative');
		}

		$parameters = $request->parameters;
		$createdMetadataId = null;
		if (isset($request->startDuration))
		{
			$metadataWorkflowId =
				$parameters[\CBPDocument::PARAM_PRE_GENERATED_WORKFLOW_ID]
				?? \CBPRuntime::generateWorkflowId()
			;
			$parameters[\CBPDocument::PARAM_PRE_GENERATED_WORKFLOW_ID] = $metadataWorkflowId;

			$metadata = new EO_WorkflowMetadata();
			$metadata->setWorkflowId($metadataWorkflowId);
			$metadata->setStartDuration($request->startDuration);
			$saveResult = $metadata->save();
			if ($saveResult->isSuccess())
			{
				$createdMetadataId = $metadata->getId();
			}
		}

		$startWorkflowErrors = [];
		$instanceId = \CBPDocument::startWorkflow(
			$request->templateId,
			$request->complexDocumentId,
			$parameters,
			$startWorkflowErrors,
			$request->parentWorkflow,
		);

		if (($startWorkflowErrors || is_null($instanceId)) && $createdMetadataId !== null)
		{
			WorkflowMetadataTable::delete($createdMetadataId);
		}

		if ($startWorkflowErrors)
		{
			foreach ($startWorkflowErrors as $error)
			{
				if (is_numeric($error['code']))
				{
					$response->addError(new Error($error['message'], (int)$error['code']));
				}
				else
				{
					$response->addError(new Error($error['message']));
				}
			}
		}
		elseif (is_null($instanceId))
		{
			$response->addError(
				new Error(Loc::getMessage(static::PREFIX_LOC_ID . static::UNKNOWN_CREATE_WORKFLOW_ERROR))
			);
		}
		else
		{
			$response->setWorkflowId($instanceId);
		}

		return $response;
	}

	public function terminateWorkflow(TerminateWorkflowRequest $request): TerminateWorkflowResponse
	{
		$response = new TerminateWorkflowResponse();

		$documentId = \CBPStateService::getStateDocumentId($request->workflowId);
		if (!$documentId)
		{
			return $response->addError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_LIB_API_WORKFLOW_SERVICE_COMPLETED')
			));
		}

		$documentStates = \CBPDocument::getActiveStates($documentId);

		if (empty($documentStates[$request->workflowId]))
		{
			return $response->addError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_LIB_API_WORKFLOW_SERVICE_COMPLETED')
			));
		}

		$canTerminate = \CBPDocument::CanUserOperateDocument(
			\CBPCanUserOperateOperation::StartWorkflow,
			$request->userId,
			$documentId,
			['DocumentStates' => $documentStates]
		);

		if (!$canTerminate)
		{
			$response->addError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_LIB_API_WORKFLOW_SERVICE_NO_ACCESS')
			));

			return $response;
		}

		$this->terminateWorkflowById($request->workflowId, $documentId, $response);

		return $response;
	}

	public function terminateWorkflowsByTemplate(TerminateByTemplateRequest $request): TerminateWorkflowResponse
	{
		$response = new TerminateWorkflowResponse();
		$documentStates = \CBPDocument::getActiveStates($request->documentId);

		$canTerminate = \CBPDocument::CanUserOperateDocument(
			\CBPCanUserOperateOperation::CreateAutomation,
			$request->userId,
			$request->documentId,
			['DocumentStates' => $documentStates]
		);

		if (!$canTerminate)
		{
			$response->addError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_LIB_API_WORKFLOW_SERVICE_ROBOTS_NO_ACCESS')
			));

			return $response;
		}

		$instanceIds = $this->getWorkflowInstanceIds($request->templateId, $request->documentId);

		if (empty($instanceIds))
		{
			$response->addError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_LIB_API_WORKFLOW_SERVICE_ROBOTS_NOT_FOUND')
			));
		}

		foreach ($instanceIds as $instanceId)
		{
			$this->terminateWorkflowById($instanceId, $request->documentId, $response);
		}

		return $response;
	}

	private function getWorkflowInstanceIds(int $templateId, array $documentId): array
	{
		$ids = WorkflowInstanceTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=WORKFLOW_TEMPLATE_ID' => $templateId,
				'=MODULE_ID' => $documentId[0],
				'=ENTITY' => $documentId[1],
				'=DOCUMENT_ID' => $documentId[2],
				'@TEMPLATE.TYPE' => [WorkflowTemplateType::CustomRobots->value, WorkflowTemplateType::Robots->value],
			],
		])->fetchAll();

		return array_column($ids, 'ID');
	}

	private function terminateWorkflowById(string $workflowId, array $documentId, TerminateWorkflowResponse $response)
	{
		$errors = [];
		\CBPDocument::TerminateWorkflow($workflowId, $documentId, $errors);

		if (!empty($errors))
		{
			foreach ($errors as $error)
			{
				$response->addError(new Error($error['message'], $error['code']));
			}
		}
	}
}
