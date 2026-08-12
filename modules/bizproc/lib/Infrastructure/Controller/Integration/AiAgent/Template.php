<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller\Integration\AiAgent;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use CBPWorkflowTemplateUser;

use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\DI\ServiceLocator;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\CreateSource;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;


class Template extends BaseController
{
	private readonly SystemTemplateActivationService $activationService;
	private readonly TemplateDeleteService $templateDeleteService;
	private readonly AiAgentsGridHelper $aiAgentGridHelper;
	private readonly AiAgentsFeature $aiAgentsFeature;
	private readonly AiAgentProvider $aiAgentProvider;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->activationService = ServiceLocator::getInstance()->get(SystemTemplateActivationService::class);
		$this->templateDeleteService = ServiceLocator::getInstance()->get(TemplateDeleteService::class);
		$this->aiAgentGridHelper = ServiceLocator::getInstance()->get(AiAgentsGridHelper::class);
		$this->aiAgentsFeature = ServiceLocator::getInstance()->get(AiAgentsFeature::class);
		$this->aiAgentProvider = ServiceLocator::getInstance()->get(AiAgentProvider::class);
	}

	public function startAction(int $templateId): array
	{
		if (!$this->isRestartAvailable($templateId))
		{
			return [];
		}

		if (!$this->canCurrentUserManageLaunchedTemplate($templateId, requireStarted: true))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

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
		if ($templateId <= 0)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $templateId]));

			return [];
		}

		// both a system AI-agent template and a user-created one (added via the designer) may be
		// copied & started; a launched copy, a template of another type/section or a missing
		// template is rejected
		$aiAgentTemplate = $this->aiAgentProvider->findCopyableAiAgentTemplate($templateId);
		if ($aiAgentTemplate === null)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return [];
		}

		// SYSTEM_CODE is null for a user-created agent (copied as CreateSource::User); for a system
		// template it also selects the booking scenario copy source
		$createSource = $this->resolveCopyCreateSource($aiAgentTemplate['SYSTEM_CODE']);
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

		$copyResult = $this->activationService->copyTemplate($templateId, $userId, $createSource);
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

	private function canCurrentUserManageLaunchedTemplate(int $templateId, bool $requireStarted = false): bool
	{
		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);

		return $this->aiAgentProvider->canManageLaunchedTemplate(
			$templateId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
			$requireStarted,
		);
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
	private function resolveCopyCreateSource(?string $systemCode): CreateSource
	{
		return $systemCode === 'bitrix_booking_ai_call'
			? CreateSource::Scenario
			: CreateSource::User
		;
	}
}