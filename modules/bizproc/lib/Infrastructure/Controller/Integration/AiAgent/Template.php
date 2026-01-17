<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller\Integration\AiAgent;

use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\DI\ServiceLocator;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use CBPWorkflowTemplateUser;


class Template extends JsonController
{
	private readonly SystemTemplateActivationService $activationService;
	private readonly TemplateDeleteService $templateDeleteService;
	private readonly AiAgentsGridHelper $aiAgentGridHelper;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->activationService = ServiceLocator::getInstance()->get(SystemTemplateActivationService::class);
		$this->templateDeleteService = ServiceLocator::getInstance()->get(TemplateDeleteService::class);
		$this->aiAgentGridHelper = ServiceLocator::getInstance()->get(AiAgentsGridHelper::class);
	}

	public function startAction(int $templateId): array
	{
		$includeResult = $this->activationService->includeModuleAi();

		if (!$includeResult->isSuccess())
		{
			$this->addErrors($includeResult->getErrors());
			return [];
		}

		$startResult = $this->activationService->startTemplate($templateId);

		$this->addErrors($startResult->getErrors());

		return [];
	}

	public function copyAndStartAction(int $templateId): array
	{
		$includeResult = $this->activationService->includeModuleAi();

		if (!$includeResult->isSuccess())
		{
			$this->addErrors($includeResult->getErrors());

			return [];
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return [];
		}

		if ($templateId <= 0)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $templateId]));

			return [];
		}

		$copyResult = $this->activationService->copyTemplate($templateId, $userId);
		if (!$copyResult instanceof TemplateCreatedResult)
		{
			$this->addErrors($copyResult->getErrors());

			return [];
		}

		$startResult = $this->activationService->startTemplate($copyResult->templateId);
		if (!$startResult->isSuccess())
		{
			$this->addErrors($startResult->getErrors());

			return [];
		}

		return $this->prepareCopyAndStartResponseData($copyResult);
	}

	/**
	 * @param array<int> $agentIds
	 */
	public function deleteAction(array $agentIds): Result
	{
		$currentUser = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		return $this->templateDeleteService->deleteTemplates(
			templateIds: $agentIds,
			initiator: $currentUser,
		);
	}
	
	public function fetchRowAction(int $templateId): array
	{
		return $this->aiAgentGridHelper->getRowFieldsByTemplateId($templateId);
	}

	private function prepareCopyAndStartResponseData(Result $result): array
	{
		$data = $result->getData();
		$rawFields = $data['rawTemplateFields'] ?? [];

		if (empty($rawFields))
		{
			return [];
		}

		return $this->aiAgentGridHelper->prepareGridRowDataFromTemplateFields($rawFields);
	}
}