<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\AI\Limiter\Enums\TypeLimit;
use Bitrix\Landing\Copilot\Connector;
use Bitrix\Landing\Copilot\Connector\AI\RequestLimiter;
use Bitrix\Landing\Copilot\Connector\Chat\ChatBotMessageDto;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Generation\Type\ScenarioStepDto;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;
use Bitrix\Main\Loader;

class Scenarist
{
	private const EVENT_STEP = 'onExecuteStep';

	private IScenario $scenario;
	private Generation $generation;

	private ?int $stepId;

	/**
	 * @var ScenarioStepDto[]
	 */
	private array $steps;

	/**
	 * @var callable - call when stepId changed and must be saved
	 */
	private $onStepChangeCallback;

	/**
	 * @var callable - call when site data changed and must be saved
	 */
	private $onSaveCallback;

	/**
	 * @var callable - call when scenario finished
	 */
	private $onFinishCallback;

	private RequestLimiter $requestLimiter;

	public function __construct(IScenario $scenario, Generation $generation)
	{
		$this->scenario = $scenario;
		$this->generation = $generation;

		$this->stepId = $this->generation->getStep();

		$this->initSteps();
	}

	private function initSteps(): void
	{
		foreach ($this->scenario->getMap() as $stepId => $step)
		{
			$scenarioStep = new ScenarioStepDto(
				$stepId,
				$step,
				StepStatuses::New,
			);
			$this->steps[$stepId] = $scenarioStep;
		}

		$query = StepsTable::query()
			->setSelect(['ID', 'STEP_ID', 'STATUS'])
			->where('GENERATION_ID', $this->generation->getId())
			->exec()
		;
		while ($step = $query->fetch())
		{
			if (
				isset($this->steps[(int)$step['STEP_ID']])
				&& StepStatuses::tryFrom((int)$step['STATUS'])
			)
			{
				$this->steps[(int)$step['STEP_ID']]->status =
					StepStatuses::from((int)$step['STATUS']);
				$this->steps[(int)$step['STEP_ID']]->entityId = (int)$step['ID'];
			}
		}
	}

	public function getStep(): ?int
	{
		return $this->stepId;
	}

	/**
	 * Check if scenario execute all steps
	 * @return bool
	 */
	public function isFinished(): bool
	{
		foreach ($this->steps as $step)
		{
			if ($step->status !== StepStatuses::Finished)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark all scenario steps as completed
	 * @return void
	 */
	public function finish(): void
	{
		foreach ($this->steps as $step)
		{
			if ($step->status !== StepStatuses::Finished)
			{
				$this->saveStepStatus($step, StepStatuses::Finished);
			}
		}
	}

	/**
	 * Check if at least one scenario step has error and not execute
	 * @return bool
	 */
	public function isError(): bool
	{
		foreach ($this->steps as $step)
		{
			if ($step->status === StepStatuses::Error)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Prepare scenario to restart generation after error.
	 * @return void
	 */
	public function clearErrors(): void
	{
		foreach ($this->steps as $step)
		{
			if (
				$step->status->value > StepStatuses::New->value
				&& $step->status->value < StepStatuses::Finished->value
			)
			{
				$step->step->init($this->generation, $step->stepId);
				$step->step->clearErrors();
				$this->saveStepStatus($step, StepStatuses::New);
			}
		}
	}

	public function onStepChange(callable $callback): self
	{
		$this->onStepChangeCallback = $callback;

		return $this;
	}

	private function callOnStepChange(): void
	{
		if (isset($this->onStepChangeCallback) && is_int($this->stepId))
		{
			call_user_func($this->onStepChangeCallback, $this->stepId);
		}
	}

	public function onSave(callable $callback): self
	{
		$this->onSaveCallback = $callback;

		return $this;
	}

	private function callOnSave(): void
	{
		if (isset($this->onSaveCallback))
		{
			call_user_func($this->onSaveCallback);
		}
	}

	public function onFinish(callable $callback): self
	{
		$this->onFinishCallback = $callback;

		return $this;
	}

	private function callOnFinish(): void
	{
		if (isset($this->onFinishCallback))
		{
			call_user_func($this->onFinishCallback);
		}
	}

	/**
	 * Run scenario
	 * @return void
	 * @throws GenerationException
	 */
	public function execute(): void
	{
		if (!$this->scenario->checkStep($this->stepId))
		{
			return;
		}

		$this->stepId = $this->stepId ?? $this->scenario->getFirstStepId();
		if (!$this->stepId)
		{
			return;
		}

		foreach ($this->steps as $stepId => $step)
		{
			if ($stepId > $this->stepId)
			{
				break;
			}

			if (
				$step->status === StepStatuses::Finished
				|| $step->status === StepStatuses::Error
			)
			{
				continue;
			}

			try
			{
				$this->executeStep($step);
			}
			catch (GenerationException $e)
			{
				$this->saveStepStatus($step, StepStatuses::Error);
				throw $e;
			}

			if (
				$this->stepId === $stepId
				&& (
					$step->step->isFinished()
					|| $step->step->isAsync()
				)
			)
			{
				$this->stepId = $this->scenario->getNextStepId($this->stepId);
				if (!$this->stepId)
				{
					break;
				}

				$this->callOnStepChange();
				$this->callOnSave();
			}
		}

		if ($this->isFinished())
		{
			$this->scenario->onFinish($this->generation);
			$this->callOnFinish();
		}
	}

	/**
	 * Find current step and run them
	 * @param ScenarioStepDto $step
	 * @return void
	 * @throws GenerationException
	 */
	private function executeStep(ScenarioStepDto $step): void
	{
		$step->step->init($this->generation, $step->stepId);

		if (
			!$step->step->isStarted()
			&& $step->stepId === $this->scenario->getQuotaCalculateStep()
		)
		{
			$this->checkRequestQuotas();
		}

		$step->step->execute();

		if ($step->step->isStarted())
		{
			$this->saveStepStatus($step, StepStatuses::Started);
		}

		if ($step->step->isFinished())
		{
			$this->saveStepStatus($step, StepStatuses::Finished);
		}

		if ($step->step->isChanged() || $step->step->isFinished())
		{
			$this->callOnSave();
		}

		$this->generation->getEvent()->send(self::EVENT_STEP);
	}

	private function saveStepStatus(ScenarioStepDto $step, StepStatuses $status): void
	{
		$step->status = $status;

		if (!isset($step->entityId))
		{
			$resAdd = StepsTable::add([
				'GENERATION_ID' => $this->generation->getId(),
				'STEP_ID' => $step->stepId,
				'CLASS' => $step->step::class,
				'STATUS' => $status->value,
			]);

			if ($resAdd->isSuccess())
			{
				$step->entityId = $resAdd->getId();
			}

			return;
		}

		StepsTable::update($step->entityId, [
			'STATUS' => $status->value,
		]);
	}

	/**
	 * @return void
	 * @throws GenerationException
	 */
	private function checkRequestQuotas(): void
	{
		$quotaLimitText = $this->getQuotaLimitText();
		if (is_string($quotaLimitText))
		{
			throw new GenerationException(
				GenerationErrors::requestQuotaExceeded,
				$quotaLimitText,
				[
					'errorText' => $quotaLimitText,
				]
			);
		}

		if (
			!$this->generation->getChatId()
			|| $this->generation->getChatId() <= 0
		)
		{
			return;
		}

		$this->generation->getScenario()?->getChatbot()?->onRequestQuotaOk(
			new ChatBotMessageDto(
				$this->generation->getChatId(),
				$this->generation->getId(),
			)
		);
	}

	private function getQuotaLimitText(): ?string
	{
		if (!Loader::includeModule('ai'))
		{
			return null;
		}

		$requestCount = $this->getRequestQuotasSum();
		if ($requestCount <= 0)
		{
			return null;
		}

		return $this->getRequestLimiter()->getTextFromCheckLimit($requestCount);
	}

	/**
	 * Return sum of request limits by all steps
	 *
	 * @return RequestQuotaDto[]
	 */
	private function getRequestQuotas(): array
	{
		/**
		 * @var RequestQuotaDto[] $quotas
		 */
		$quotas = [];
		foreach ($this->scenario->getMap() as $step)
		{
			$stepQuota = $step::getRequestQuota($this->generation->getSiteData());
			if (!$stepQuota)
			{
				continue;
			}

			if (isset($quotas[$stepQuota->connectorClass]))
			{
				$quotas[$stepQuota->connectorClass]->requestCount += $stepQuota->requestCount;
			}
			else
			{
				$quotas[$stepQuota->connectorClass] = $stepQuota;
			}
		}

		return $quotas;
	}

	/**
	 * Get sum of all request quotas, ignore types
	 * @return int
	 */
	private function getRequestQuotasSum(): int
	{
		$requestCount = 0;
		foreach ($this->getRequestQuotas() as $quota)
		{
			$requestCount += $quota->requestCount;
		}

		return $requestCount;
	}

	/**
	 * Retrieves the RequestLimiter instance, initializing it if not already set.
	 *
	 * @return RequestLimiter The RequestLimiter instance.
	 */
	private function getRequestLimiter(): RequestLimiter
	{
		if (empty($this->requestLimiter))
		{
			$this->requestLimiter = new RequestLimiter();
		}

		return $this->requestLimiter;
	}
}