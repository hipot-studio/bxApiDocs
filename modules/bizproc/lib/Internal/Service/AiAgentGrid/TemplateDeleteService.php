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
	private ChatbotDeleteService $chatbotDeleteService;
	private AgentChatbotsExtractor $chatbotsExtractor;

	public function __construct(
		?ChatbotDeleteService $chatbotDeleteService = null,
		?AgentChatbotsExtractor $chatbotsExtractor = null,
	)
	{
		$this->aiAgentProvider = ServiceLocator::getInstance()->get(AiAgentProvider::class);
		$this->chatbotDeleteService = $chatbotDeleteService ?? ServiceLocator::getInstance()->get(ChatbotDeleteService::class);
		$this->chatbotsExtractor = $chatbotsExtractor ?? ServiceLocator::getInstance()->get(AgentChatbotsExtractor::class);
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
			->exec()
		;

		while ($workflowInstance = $workflowInstances->fetch())
		{
			$documentId = [
				$workflowInstance['MODULE_ID'],
				$workflowInstance['ENTITY'],
				$workflowInstance['DOCUMENT_ID'],
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
	public function deleteTemplates(
		array $templateIds,
		CBPWorkflowTemplateUser $initiator,
		bool $deleteChatbots = false,
	): Result
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

		$botIdsToDelete = [
			AgentChatbotsExtractor::KIND_BIZPROC => [],
			AgentChatbotsExtractor::KIND_OPENLINES => [],
		];

		try
		{
			foreach ($agentIds as $agentId)
			{
				$agentBotIds = $deleteChatbots
					? $this->chatbotsExtractor->getCreatedBotIds([$agentId])
					: null;

				\CBPWorkflowTemplateLoader::delete($agentId);

				if ($agentBotIds !== null)
				{
					$botIdsToDelete[AgentChatbotsExtractor::KIND_BIZPROC] = [
						...$botIdsToDelete[AgentChatbotsExtractor::KIND_BIZPROC],
						...$agentBotIds[AgentChatbotsExtractor::KIND_BIZPROC],
					];
					$botIdsToDelete[AgentChatbotsExtractor::KIND_OPENLINES] = [
						...$botIdsToDelete[AgentChatbotsExtractor::KIND_OPENLINES],
						...$agentBotIds[AgentChatbotsExtractor::KIND_OPENLINES],
					];
				}
			}
		}
		catch (\Exception)
		{
			$result->addError(ErrorMessage::AI_AGENT_DELETE_ERROR->getError());
		}
		finally
		{
			$this->deleteChatbotsSafe($deleteChatbots, $botIdsToDelete);
		}

		return $result;
	}

	/**
	 * @param array{bizproc: list<int>, openlines: list<int>} $botIds
	 */
	private function deleteChatbotsSafe(bool $deleteChatbots, array $botIds): void
	{
		if (!$deleteChatbots)
		{
			return;
		}

		try
		{
			$this->chatbotDeleteService->deleteBots($botIds);
		}
		catch (\Exception)
		{
			// Bot cleanup is best-effort: agents are already deleted, don't fail the whole operation.
		}
	}
}
