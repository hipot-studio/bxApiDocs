<?php

namespace Bitrix\Crm\AutomatedSolution\Vibe;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Integration\Landing\Vibe\VibeGateway;
use Bitrix\Crm\Integration\Landing\Vibe\VibeRepository;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;

final class LifecycleService
{
	private readonly \Closure $isVibeAvailable;
	private readonly \Closure $isFeatureEnabled;

	public function __construct(
		private readonly VibeGateway $gateway,
		private readonly VibeLock $lock,
		private readonly StateRepository $stateRepository = new StateRepository(),
		?\Closure $isVibeAvailable = null,
		?\Closure $isFeatureEnabled = null,
	)
	{
		$this->isVibeAvailable = $isVibeAvailable ?? static fn(): bool => VibeRepository::isVibeAvailable();
		$this->isFeatureEnabled = $isFeatureEnabled ?? static fn(): bool => Feature::enabled(AutomatedSolutionVibe::class);
	}

	public function enable(int $automatedSolutionId): Result
	{
		if (!$this->isVibeAvailable())
		{
			return $this->errorResult(
				$automatedSolutionId,
				new Error('Vibe is unavailable.', 'VIBE_UNAVAILABLE'),
			);
		}

		if (!$this->isFeatureEnabled())
		{
			return $this->stateResult($automatedSolutionId);
		}

		if (!$this->lock->acquire($automatedSolutionId))
		{
			return $this->errorResult(
				$automatedSolutionId,
				new Error('Vibe operation is already in progress.', 'VIBE_OPERATION_IN_PROGRESS'),
			);
		}

		$creationToken = null;
		$createdReference = null;

		try
		{
			$state = $this->stateRepository->get($automatedSolutionId);
			if ($state === null)
			{
				return $this->stateResult($automatedSolutionId);
			}

			$embedId = self::getEmbedId($automatedSolutionId);
			$reference = $this->gateway->findByEmbedId($embedId);
			if ($reference !== null && !$reference->isCreated)
			{
				$deleteResult = $this->gateway->delete($reference);
				if (!$deleteResult->isSuccess())
				{
					return $this->stateResult($automatedSolutionId)->addErrors($deleteResult->getErrors());
				}

				$reference = null;
			}

			if (!empty($state['VIBE_CREATION_TOKEN']) && $reference !== null)
			{
				$isEnabled = $this->isFeatureEnabled();
				$writeResult = $this->stateRepository->recoverCreation(
					$automatedSolutionId,
					$state['VIBE_CREATION_TOKEN'],
					$reference->id,
					$isEnabled,
				);

				return $this->completeStateWrite($automatedSolutionId, $writeResult, $isEnabled);
			}

			if (!empty($state['VIBE_CREATION_TOKEN']) && $reference === null)
			{
				$writeResult = $this->stateRepository->cancelCreation(
					$automatedSolutionId,
					$state['VIBE_CREATION_TOKEN'],
				);
				if (!$writeResult->isApplied())
				{
					return $this->completeStateWrite($automatedSolutionId, $writeResult);
				}

				$state = $this->stateRepository->get($automatedSolutionId);
				if ($state === null)
				{
					return $this->stateResult($automatedSolutionId);
				}
			}

			if ($reference !== null)
			{
				$isEnabled = $this->isFeatureEnabled();
				$writeResult = $this->stateRepository->repairVibe(
					$automatedSolutionId,
					(int)$state['VIBE_ID'],
					$reference->id,
					$isEnabled,
				);

				return $this->completeStateWrite($automatedSolutionId, $writeResult, $isEnabled);
			}

			if ((int)$state['VIBE_ID'] > 0)
			{
				$writeResult = $this->stateRepository->resetBrokenVibe(
					$automatedSolutionId,
					(int)$state['VIBE_ID'],
				);
				if (!$writeResult->isApplied())
				{
					return $this->completeStateWrite($automatedSolutionId, $writeResult);
				}

				$state = $this->stateRepository->get($automatedSolutionId);
				if ($state === null)
				{
					return $this->stateResult($automatedSolutionId);
				}
			}

			$creationToken = Random::getString(36);
			$writeResult = $this->stateRepository->claimCreation($automatedSolutionId, $creationToken);
			if (!$writeResult->isApplied())
			{
				return $this->completeStateWrite($automatedSolutionId, $writeResult);
			}

			$reference = $this->gateway->findByEmbedId($embedId);
			if ($reference === null)
			{
				if (!$this->isFeatureEnabled())
				{
					$writeResult = $this->stateRepository->cancelCreation($automatedSolutionId, $creationToken);

					return $this->completeStateWrite($automatedSolutionId, $writeResult);
				}

				$reference = $this->gateway->create($embedId);
				if ($reference === null)
				{
					$writeResult = $this->stateRepository->cancelCreation($automatedSolutionId, $creationToken);
					$result = $this->completeStateWrite($automatedSolutionId, $writeResult);
					$result->addError(new Error('Unable to create Vibe.', 'VIBE_CREATION_FAILED'));

					return $result;
				}

				$createdReference = $reference;
			}

			$isEnabled = $this->isFeatureEnabled();
			$writeResult = $this->stateRepository->completeCreation(
				$automatedSolutionId,
				$creationToken,
				$reference->id,
				$isEnabled,
			);

			return $this->completeStateWrite($automatedSolutionId, $writeResult, $isEnabled);
		}
		catch (\Throwable $exception)
		{
			$this->logException('enable', $automatedSolutionId, $exception);

			if ($creationToken !== null)
			{
				$writeResult = $this->stateRepository->cancelCreation($automatedSolutionId, $creationToken);
				if ($writeResult->isApplied() && $createdReference !== null)
				{
					try
					{
						$this->gateway->delete($createdReference);
					}
					catch (\Throwable)
					{
					}
				}
			}

			return $this->errorResult(
				$automatedSolutionId,
				new Error('Unable to change the Vibe state.', 'VIBE_LIFECYCLE_FAILED'),
			);
		}
		finally
		{
			$this->lock->release();
		}
	}

	public function disable(int $automatedSolutionId): Result
	{
		if (!$this->lock->acquire($automatedSolutionId))
		{
			return $this->errorResult(
				$automatedSolutionId,
				new Error('Vibe operation is already in progress.', 'VIBE_OPERATION_IN_PROGRESS'),
			);
		}

		try
		{
			$state = $this->stateRepository->get($automatedSolutionId);
			if ($state === null || !$state['IS_VIBE_ENABLED'])
			{
				return $this->stateResult($automatedSolutionId);
			}

			$writeResult = $this->stateRepository->disable($automatedSolutionId);

			return $this->completeStateWrite($automatedSolutionId, $writeResult, true);
		}
		finally
		{
			$this->lock->release();
		}
	}

	public function delete(int $automatedSolutionId, \Closure $deleteAutomatedSolution): Result
	{
		if (!$this->lock->acquire($automatedSolutionId))
		{
			return $this->errorResult(
				$automatedSolutionId,
				new Error('Vibe operation is already in progress.', 'VIBE_OPERATION_IN_PROGRESS'),
			);
		}

		try
		{
			$state = $this->stateRepository->get($automatedSolutionId);
			if ($state === null)
			{
				return new Result();
			}
			if ($this->isVibeAvailable())
			{
				$reference = $this->gateway->findByEmbedId(self::getEmbedId($automatedSolutionId));
				if ($reference !== null)
				{
					$deleteVibeResult = $this->gateway->delete($reference);
					if (!$deleteVibeResult->isSuccess())
					{
						return (new Result())->addErrors($deleteVibeResult->getErrors());
					}
				}
			}

			$deleteResult = $deleteAutomatedSolution($state);
			if (!$deleteResult->isSuccess())
			{
				return $deleteResult;
			}

			IntranetManager::clearLeftMenuCache();

			return new Result();
		}
		catch (\Throwable $exception)
		{
			$this->logException('delete', $automatedSolutionId, $exception);

			return (new Result())->addError(new Error('Unable to delete Vibe.', 'VIBE_DELETION_FAILED'));
		}
		finally
		{
			$this->lock->release();
		}
	}

	public function synchronizePublication(int $automatedSolutionId, bool $isVibeEnabled): void
	{
		if (!$this->isFeatureEnabled())
		{
			return;
		}

		$state = $this->stateRepository->get($automatedSolutionId);
		if ($state === null || !empty($state['VIBE_CREATION_TOKEN']))
		{
			return;
		}

		$reference = $this->gateway->findByEmbedId(self::getEmbedId($automatedSolutionId));
		if ($reference === null)
		{
			return;
		}

		if (!$this->lock->acquire($automatedSolutionId))
		{
			return;
		}

		try
		{
			if (!$this->isFeatureEnabled())
			{
				return;
			}

			$state = $this->stateRepository->get($automatedSolutionId);
			if (
				$state === null
				|| !empty($state['VIBE_CREATION_TOKEN'])
				|| (int)$state['VIBE_ID'] !== $reference->id
			)
			{
				return;
			}

			$writeResult = $this->stateRepository->synchronizePublication(
				$automatedSolutionId,
				$reference->id,
				$isVibeEnabled,
			);
			$this->completeStateWrite($automatedSolutionId, $writeResult, true);
		}
		finally
		{
			$this->lock->release();
		}
	}

	public static function getEmbedId(int $automatedSolutionId): string
	{
		return "crm_automated_solution_{$automatedSolutionId}";
	}

	private function completeStateWrite(
		int $automatedSolutionId,
		StateWriteResult $writeResult,
		bool $invalidateMenu = false,
	): Result
	{
		if ($writeResult->isApplied() && $invalidateMenu)
		{
			IntranetManager::clearLeftMenuCache();
		}

		if ($writeResult->status === StateWriteResult::FAILURE && $writeResult->error !== null)
		{
			return $this->errorResult($automatedSolutionId, $writeResult->error);
		}

		return $this->stateResult($automatedSolutionId);
	}

	private function stateResult(int $automatedSolutionId): Result
	{
		return (new Result())->setData([
			'fields' => $this->stateRepository->get($automatedSolutionId) ?? [],
		]);
	}

	private function errorResult(int $automatedSolutionId, Error $error): Result
	{
		return $this->stateResult($automatedSolutionId)->addError($error);
	}

	private function logException(string $operation, int $automatedSolutionId, \Throwable $exception): void
	{
		try
		{
			Container::getInstance()->getLogger('Default')->error(
				'Vibe lifecycle operation failed.',
				[
					'operation' => $operation,
					'automatedSolutionId' => $automatedSolutionId,
					'exceptionClass' => $exception::class,
					'exceptionMessage' => $exception->getMessage(),
					'exceptionFile' => $exception->getFile(),
					'exceptionLine' => $exception->getLine(),
					'exceptionTrace' => $exception->getTraceAsString(),
				],
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function isVibeAvailable(): bool
	{
		return ($this->isVibeAvailable)();
	}

	private function isFeatureEnabled(): bool
	{
		return ($this->isFeatureEnabled)();
	}
}
