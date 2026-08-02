<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\HumanResources;

use Bitrix\Bizproc\Api\Enum\Template\CreateSource;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\LaunchedTemplateRepository;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class DayPlannerStarter
{
	private const SYSTEM_CODE = 'bitrix_ai_day_planner';
	private const LOCK_NAME = 'bp_day_planner_activate';
	private const LOCK_WAIT_SECONDS = 30;

	private readonly SystemTemplateActivationService $activationService;
	private readonly LaunchedTemplateRepository $launchedTemplateRepository;
	private readonly SetupTemplateService $setupTemplateService;
	private readonly TemplateDeleteService $templateDeleteService;

	public function __construct(
		?SystemTemplateActivationService $activationService = null,
		?LaunchedTemplateRepository $launchedTemplateRepository = null,
		?SetupTemplateService $setupTemplateService = null,
		?TemplateDeleteService $templateDeleteService = null,
	)
	{
		$this->activationService = $activationService
			?? new SystemTemplateActivationService(new AiAgentRepository());
		$this->launchedTemplateRepository = $launchedTemplateRepository ?? new LaunchedTemplateRepository();
		$this->setupTemplateService = $setupTemplateService ?? new SetupTemplateService();
		$this->templateDeleteService = $templateDeleteService ?? new TemplateDeleteService();
	}

	public function handleNodeEnabled(int $nodeId, int $userId): void
	{
		if ($nodeId <= 0)
		{
			return;
		}

		if ($this->isAlreadyActivated())
		{
			return;
		}

		$connection = Application::getInstance()->getConnection();
		if (!$connection->lock(self::LOCK_NAME, self::LOCK_WAIT_SECONDS))
		{
			return;
		}

		try
		{
			if ($this->isAlreadyActivated())
			{
				return;
			}

			$this->activateAgent($userId);
		}
		finally
		{
			try
			{
				$connection->unlock(self::LOCK_NAME);
			}
			catch (\Throwable)
			{
			}
		}
	}

	private function isAlreadyActivated(): bool
	{
		return $this->launchedTemplateRepository->getIdsBySystemCodes([self::SYSTEM_CODE]) !== [];
	}

	private function activateAgent(int $userId): void
	{
		$systemTemplateId = $this->findSystemTemplateId();
		if ($systemTemplateId <= 0)
		{
			return;
		}

		$copyResult = $this->activationService->copyTemplate(
			$systemTemplateId,
			$userId,
			CreateSource::Scenario,
		);
		if (!$copyResult instanceof TemplateCreatedResult)
		{
			return;
		}

		$result = $this->tryStartAndFillAgent($copyResult->templateId, $userId);
		if (!$result->isSuccess())
		{
			$this->logStartFailure($copyResult->templateId, $result->getErrors());
			$this->rollbackCopy($copyResult->templateId);
		}
	}

	private function tryStartAndFillAgent(int $templateId, int $userId): Result
	{
		$startResult = $this->activationService->startTemplate($templateId, $userId);
		if (!$startResult->isSuccess())
		{
			return $startResult;
		}

		if (!$startResult->setupTemplateEvent)
		{
			return (new Result())->addError(new Error('Setup template event is missing'));
		}

		$instanceId = $startResult->setupTemplateEvent->getInstanceId();
		if (empty($instanceId))
		{
			return (new Result())->addError(new Error('Instance id is missing in setup template event'));
		}

		return $this->setupTemplateService->fill(
			$userId,
			$templateId,
			$instanceId,
			applyDefaults: true,
		);
	}

	private function findSystemTemplateId(): int
	{
		$row = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('SYSTEM_CODE', self::SYSTEM_CODE)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->setLimit(1)
			->fetch()
		;

		return (int)($row['ID'] ?? 0);
	}

	private function rollbackCopy(int $templateId): void
	{
		try
		{
			$this->templateDeleteService->killWorkflow([$templateId]);
			\CBPWorkflowTemplateLoader::delete($templateId);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * @param Error[] $errors
	 */
	private function logStartFailure(int $templateId, array $errors): void
	{
		$details = sprintf('[DayPlannerStarter] failed to start agent template #%d', $templateId);
		foreach ($errors as $error)
		{
			if ($error instanceof Error)
			{
				$details .= "\n - " . $error->getCode() . ': ' . $error->getMessage();
			}
		}

		Application::getInstance()->getExceptionHandler()->writeToLog(new \Exception($details));
	}
}
