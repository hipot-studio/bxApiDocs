<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller\Integration\AiAgent;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use CBPWorkflowTemplateUser;

use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\DI\ServiceLocator;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\CreateSource;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Internal\Service\Tariff\TariffChecker;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;


class Template extends JsonController
{
	private readonly SystemTemplateActivationService $activationService;
	private readonly TemplateDeleteService $templateDeleteService;
	private readonly AiAgentsGridHelper $aiAgentGridHelper;
	private readonly AiAgentsFeature $aiAgentsFeature;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->activationService = ServiceLocator::getInstance()->get(SystemTemplateActivationService::class);
		$this->templateDeleteService = ServiceLocator::getInstance()->get(TemplateDeleteService::class);
		$this->aiAgentGridHelper = ServiceLocator::getInstance()->get(AiAgentsGridHelper::class);
		$this->aiAgentsFeature = ServiceLocator::getInstance()->get(AiAgentsFeature::class);
	}

	public function startAction(int $templateId): array
	{
		if (!$this->isRestartAvailable($templateId))
		{
			return [];
		}

		$includeResult = $this->activationService->includeModuleAi();

		if (!$includeResult->isSuccess())
		{
			$this->addErrors($includeResult->getErrors());
			return [];
		}

		$startResult = $this->activationService->startTemplate($templateId);

		$this->addErrors($startResult->getErrors());

		return $startResult->getData();
	}

	public function copyAndStartAction(int $templateId): array
	{
		$createSource = $this->resolveCopyCreateSource($templateId);
		if (
			$createSource === CreateSource::User
			 && !$this->isAgentsFeatureAvailable()
		)
		{
			return [];
		}

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

		$copyResult = $this->activationService->copyTemplate($templateId, $userId, $this->resolveCopyCreateSource($templateId));
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

		return $this->prepareCopyAndStartResponseData($copyResult, $startResult);
	}

	/**
	 * @param array<int> $agentIds
	 */
	public function deleteAction(array $agentIds, bool $deleteChatbots = false): array
	{
		$currentUser = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$deleteResult = $this->templateDeleteService->deleteTemplates(
			templateIds: $agentIds,
			initiator: $currentUser,
			deleteChatbots: $deleteChatbots,
		);

		$this->addErrors($deleteResult->getErrors());

		return [];
	}
	
	public function fetchRowAction(int $templateId): array
	{
		if (!$this->isRestartAvailable($templateId))
		{
			return [];
		}

		return $this->aiAgentGridHelper->getRowFieldsByTemplateId($templateId);
	}

	private function prepareCopyAndStartResponseData(Result $copyResult, AiAgentStartResult $startResult): array
	{
		$data = $copyResult->getData();
		$rawFields = (array)($data['rawTemplateFields'] ?? []);

		if (empty($rawFields))
		{
			return [];
		}

		$gridFields = $this->aiAgentGridHelper->prepareGridRowDataFromTemplateFields($rawFields);

		return $gridFields + [
			AiAgentStartResult::SETUP_TEMPLATE_DATA => $startResult->setupTemplateEvent?->toArray()
		];
	}

	private function isAgentsFeatureAvailable(): bool
	{
		$isAiAgentFeatureAvailable = $this->aiAgentsFeature->isAvailable();

		if ($isAiAgentFeatureAvailable)
		{
			return true;
		}

		$error = $this->aiAgentsFeature->makeUnavailableByTariffError();
		$this->addError($error);

		return false;
	}

	private function isRestartAvailable(int $templateId): bool
	{
		$isRestartAvailable = $this->aiAgentsFeature->isRestartAvailable($templateId);

		if ($isRestartAvailable)
		{
			return true;
		}

		$error = $this->aiAgentsFeature->makeUnavailableByTariffError();
		$this->addError($error);

		return false;
	}

	/**
	 * @todo Temporary workaround: for the bitrix_booking_ai_call system template we copy with CreateSource::Scenario.
	 *       Remove once a generic mechanism for resolving the copy source per template is in place.
	 */
	private function resolveCopyCreateSource(int $templateId): CreateSource
	{
		$systemCode = WorkflowTemplateTable::getList([
			'select' => ['SYSTEM_CODE'],
			'filter' => ['=ID' => $templateId],
			'limit' => 1,
		])->fetch()['SYSTEM_CODE'] ?? null;

		return $systemCode === 'bitrix_booking_ai_call'
			? CreateSource::Scenario
			: CreateSource::User
		;
	}
}