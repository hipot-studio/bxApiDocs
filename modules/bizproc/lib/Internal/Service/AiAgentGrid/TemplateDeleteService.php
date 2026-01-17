<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use CBPWorkflowTemplateUser;

class TemplateDeleteService
{
	private readonly AiAgentProvider $aiAgentProvider;

	public function __construct()
	{
		$this->aiAgentProvider = ServiceLocator::getInstance()->get(AiAgentProvider::class);
	}

	/**
	 * @param list<int> $templateIds
	 */
	public function killWorkflow(array $templateIds): array
	{
		$errors = [];

		$workflowInstances = WorkflowInstanceTable::query()
			->setSelect([
				'ID',
				'MODULE_ID',
				'ENTITY',
				'DOCUMENT_ID',
				'WORKFLOW_TEMPLATE_ID',
			])
			->whereIn('WORKFLOW_TEMPLATE_ID', $templateIds)
			->fetchAll()
		;

		foreach ($workflowInstances as $workflowInstance)
		{
			$documentId = [
				$workflowInstance['MODULE_ID'],
				$workflowInstance['ENTITY'],
				$workflowInstance['DOCUMENT_ID']
			];

			$errorsAfterKillingWorkflow = \CBPDocument::killWorkflow(
				workflowId: $workflowInstance['ID'],
				documentId: $documentId,
			);

			if (
				!empty($errorsAfterKillingWorkflow)
				&& is_array($errorsAfterKillingWorkflow)
			)
			{
				$errors = [...$errors, ...$errorsAfterKillingWorkflow];
			}
		}

		return $errors;
	}

	/**
	 * @param list<int> $templateIds
	 */
	public function deleteTemplates(array $templateIds, CBPWorkflowTemplateUser $initiator): Result
	{
		$result = new Result();

		$isUserAdmin = $initiator->isAdmin();
		$currentUserId = $initiator->getId();

		$agentIds = $this->aiAgentProvider->getOnlyExistAndAllowedToDeleteTemplateIds(
			$templateIds,
			$currentUserId,
			$isUserAdmin,
		);
		if (empty($agentIds))
		{
			$result->addError(ErrorMessage::NO_AVAILABLE_AI_AGENTS_TO_DELETE->getError());

			return $result;
		}

		$killWorkflowErrors = $this->killWorkflow($agentIds);
		if (!empty($killWorkflowErrors))
		{
			$result->addErrors($killWorkflowErrors);

			return $result;
		}

		try
		{
			foreach ($templateIds as $templateId)
			{
				\CBPWorkflowTemplateLoader::delete($templateId);
			}
		}
		catch (\Exception $e)
		{
			$result->addError(ErrorMessage::AI_AGENT_DELETE_ERROR->getError());
		}

		return $result;
	}
}