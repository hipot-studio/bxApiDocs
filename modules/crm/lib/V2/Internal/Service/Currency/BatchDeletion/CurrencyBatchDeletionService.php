<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Currency\BatchDeletion;

use Bitrix\Crm\V2\Internal\Entity\Currency\BatchDeletion\BatchDeletionContext;
use Bitrix\Crm\V2\Internal\Repository\Currency\CurrencyRepository;
use Bitrix\Crm\V2\Internal\Service\Currency\Compatibility\LegacyCurrencyMutationAdapter;
use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

class CurrencyBatchDeletionService
{
	public const ERROR_BUSY = 'CRM_CURRENCY_BATCH_BUSY';
	public const ERROR_NOT_FOUND = 'CRM_CURRENCY_BATCH_NOT_FOUND';
	public const ERROR_CONTEXT_MISMATCH = 'CRM_CURRENCY_BATCH_CONTEXT_MISMATCH';
	public const ERROR_INVALID_MODE = 'CRM_CURRENCY_BATCH_INVALID_MODE';
	public const ERROR_INVALID_TOKEN = 'CRM_CURRENCY_BATCH_INVALID_TOKEN';
	public const ERROR_OPERATION_QUOTA_EXCEEDED = 'CRM_CURRENCY_BATCH_OPERATION_QUOTA_EXCEEDED';
	public const ERROR_TOO_MANY_ITEMS = 'CRM_CURRENCY_BATCH_TOO_MANY_ITEMS';

	private const LOCK_PREFIX = 'crm_currency_batch_';
	private const LOCK_TIMEOUT = 5;
	private const DEFAULT_CHUNK_SIZE = 10;
	private const MAX_ACTIVE_OPERATIONS = 4;
	private const ACTIVE_OPERATION_TTL = 3600;
	private const MAX_BATCH_ITEMS = 1000;
	private const MAX_PROCESS_TOKEN_LENGTH = 128;
	private const PROCESS_TOKEN_PATTERN = '/^[A-Za-z0-9_-]+$/D';

	private CurrencyRepository $repository;
	private LegacyCurrencyMutationAdapter $mutationAdapter;
	private CurrencyMutationService $mutationService;

	public function __construct(
		?CurrencyRepository $repository = null,
		?LegacyCurrencyMutationAdapter $mutationAdapter = null,
		?CurrencyMutationService $mutationService = null,
		private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
	)
	{
		if ($chunkSize <= 0)
		{
			throw new \InvalidArgumentException('Batch deletion chunk size must be positive');
		}

		$this->repository = $repository ?? new CurrencyRepository();
		$this->mutationAdapter = $mutationAdapter ?? new LegacyCurrencyMutationAdapter();
		$this->mutationService = $mutationService ?? new CurrencyMutationService($this->mutationAdapter);
	}

	/** @param list<mixed> $currencyCodes */
	public function prepare(
		int $userId,
		string $gridId,
		string $processToken,
		string $mode,
		array $currencyCodes = [],
	): Result
	{
		if (!$this->isValidProcessToken($processToken))
		{
			return $this->createErrorResult(self::ERROR_INVALID_TOKEN, 'CRM_CURRENCY_BATCH_INVALID_TOKEN');
		}
		if (
			$mode === BatchDeletionContext::MODE_SELECTED
			&& count($currencyCodes) > self::MAX_BATCH_ITEMS
		)
		{
			return $this->createErrorResult(self::ERROR_TOO_MANY_ITEMS, 'CRM_CURRENCY_BATCH_TOO_MANY_ITEMS');
		}

		$storageName = $this->buildStorageName($userId, $gridId, $processToken);
		$lockName = $this->buildLockName($storageName);
		if (!$this->acquireOperationLock($lockName, self::LOCK_TIMEOUT))
		{
			return $this->createErrorResult(self::ERROR_BUSY, 'CRM_CURRENCY_BATCH_BUSY');
		}

		try
		{
			$state = $this->readOperationState($storageName);
			if ($state !== null && $this->isOperationStateExpired($state))
			{
				$this->clearOperationState($storageName);

				return $this->createErrorResult(self::ERROR_NOT_FOUND, 'CRM_CURRENCY_BATCH_NOT_FOUND');
			}
			if ($state !== null)
			{
				[$context, $itemErrors] = $this->restoreState($state);
				$identityError = $this->validateContextIdentity($context, $userId, $gridId, $processToken);
				if ($identityError !== null)
				{
					return $identityError;
				}

				return $this->createSuccessResult(
					$context->getTerminalResult() ?? $this->buildResponse($context, $itemErrors, true),
				);
			}
			if ($mode === '')
			{
				return $this->createErrorResult(self::ERROR_NOT_FOUND, 'CRM_CURRENCY_BATCH_NOT_FOUND');
			}
			if (!$this->prepareOperationStorage($storageName))
			{
				return $this->createErrorResult(
					self::ERROR_OPERATION_QUOTA_EXCEEDED,
					'CRM_CURRENCY_BATCH_OPERATION_QUOTA_EXCEEDED',
				);
			}

			$existingCodes = $this->fetchCurrencyCodes();
			$context = match ($mode)
			{
				BatchDeletionContext::MODE_SELECTED => BatchDeletionContext::createSelected(
					$userId,
					$gridId,
					$processToken,
					$currencyCodes,
					$existingCodes,
				),
				BatchDeletionContext::MODE_ALL => BatchDeletionContext::createAll(
					$userId,
					$gridId,
					$processToken,
					$existingCodes,
				),
				default => null,
			};
			if ($context === null)
			{
				return $this->createErrorResult(self::ERROR_INVALID_MODE, 'CRM_CURRENCY_BATCH_INVALID_MODE');
			}

			$itemErrors = [];
			$response = $this->buildResponse($context, $itemErrors, true);
			if ($context->getTotalCount() === 0)
			{
				$response = $this->buildResponse($context, $itemErrors, false, true);
				$context->complete($response);
			}

			$this->writeOperationState($storageName, $this->buildState($context, $itemErrors));

			return $this->createSuccessResult($response);
		}
		finally
		{
			$this->releaseOperationLock($lockName);
		}
	}

	public function process(int $userId, string $gridId, string $processToken): Result
	{
		if (!$this->isValidProcessToken($processToken))
		{
			return $this->createErrorResult(self::ERROR_INVALID_TOKEN, 'CRM_CURRENCY_BATCH_INVALID_TOKEN');
		}

		$storageName = $this->buildStorageName($userId, $gridId, $processToken);
		$lockName = $this->buildLockName($storageName);
		if (!$this->acquireOperationLock($lockName, self::LOCK_TIMEOUT))
		{
			return $this->createErrorResult(self::ERROR_BUSY, 'CRM_CURRENCY_BATCH_BUSY');
		}

		try
		{
			$state = $this->readOperationState($storageName);
			if ($state === null)
			{
				return $this->createErrorResult(self::ERROR_NOT_FOUND, 'CRM_CURRENCY_BATCH_NOT_FOUND');
			}
			if ($this->isOperationStateExpired($state))
			{
				$this->clearOperationState($storageName);

				return $this->createErrorResult(self::ERROR_NOT_FOUND, 'CRM_CURRENCY_BATCH_NOT_FOUND');
			}

			[$context, $itemErrors] = $this->restoreState($state);
			$identityError = $this->validateContextIdentity($context, $userId, $gridId, $processToken);
			if ($identityError !== null)
			{
				return $identityError;
			}

			if ($context->isTerminal())
			{
				return $this->createSuccessResult($context->getTerminalResult() ?? []);
			}

			$journal = $context->getActiveJournal();
			if ($journal === null || $journal->isComplete())
			{
				$journal = $context->activateNextChunk($this->chunkSize);
				if ($journal !== null)
				{
					$this->writeOperationState($storageName, $this->buildState($context, $itemErrors));
				}
			}
			$deletionStates = $this->fetchCurrencyDeletionStates(
				$context->getActiveJournal()?->getCodes() ?? [],
			);
			$currencyCodesToDelete = [];
			foreach ($context->getActiveJournal()?->getCodes() ?? [] as $currencyCode)
			{
				$deletionState = $deletionStates[$currencyCode] ?? null;
				if ($deletionState !== null && ($deletionState['BASE'] ?? false) !== true)
				{
					$currencyCodesToDelete[] = $currencyCode;
				}
			}
			$deleteResults = $this->deleteCurrencies($currencyCodesToDelete);

			$stepItemErrors = [];
			while (($currencyCode = $context->getActiveJournal()?->getNextUnresolvedCode()) !== null)
			{
				$deletionState = $deletionStates[$currencyCode] ?? null;

				if (($deletionState['BASE'] ?? false) === true)
				{
					$message = $this->getMessage('CRM_CURRENCY_BATCH_BASE_PROTECTED');
					$context->recordFailure($currencyCode, $message);
					$itemErrors[] = ['currencyCode' => $currencyCode, 'message' => $message];
					$stepItemErrors[] = ['currencyCode' => $currencyCode, 'message' => $message];
				}
				elseif ($deletionState === null)
				{
					$context->recordSuccess($currencyCode);
				}
				else
				{
					$deleteResult = $deleteResults[$currencyCode];
					if ($deleteResult->isSuccess())
					{
						$context->recordSuccess($currencyCode);
					}
					else
					{
						$message = $this->extractSafeErrorMessage($deleteResult);
						$context->recordFailure($currencyCode, $message);
						$itemErrors[] = ['currencyCode' => $currencyCode, 'message' => $message];
						$stepItemErrors[] = ['currencyCode' => $currencyCode, 'message' => $message];
					}
				}
			}

			if ($context->getNextIndex() === $context->getTotalCount())
			{
				$response = $this->buildResponse($context, $itemErrors, false, true);
				$context->complete($response);
				$this->writeOperationState($storageName, $this->buildState($context, $itemErrors));

				return $this->createSuccessResult($response);
			}

			$this->writeOperationState($storageName, $this->buildState($context, $itemErrors));

			return $this->createSuccessResult($this->buildResponse($context, $stepItemErrors));
		}
		finally
		{
			$this->releaseOperationLock($lockName);
		}
	}

	public function cancel(int $userId, string $gridId, string $processToken): Result
	{
		if (!$this->isValidProcessToken($processToken))
		{
			return $this->createErrorResult(self::ERROR_INVALID_TOKEN, 'CRM_CURRENCY_BATCH_INVALID_TOKEN');
		}

		$storageName = $this->buildStorageName($userId, $gridId, $processToken);
		$lockName = $this->buildLockName($storageName);
		if (!$this->acquireOperationLock($lockName, self::LOCK_TIMEOUT))
		{
			return $this->createErrorResult(self::ERROR_BUSY, 'CRM_CURRENCY_BATCH_BUSY');
		}

		try
		{
			$state = $this->readOperationState($storageName);
			if ($state === null)
			{
				return $this->createErrorResult(self::ERROR_NOT_FOUND, 'CRM_CURRENCY_BATCH_NOT_FOUND');
			}
			if ($this->isOperationStateExpired($state))
			{
				$this->clearOperationState($storageName);

				return $this->createErrorResult(self::ERROR_NOT_FOUND, 'CRM_CURRENCY_BATCH_NOT_FOUND');
			}

			[$context, $itemErrors] = $this->restoreState($state);
			$identityError = $this->validateContextIdentity($context, $userId, $gridId, $processToken);
			if ($identityError !== null)
			{
				return $identityError;
			}

			$response = $context->getTerminalResult()
				?? $this->buildResponse($context, $itemErrors, false, true);
			$this->clearOperationState($storageName);

			return $this->createSuccessResult($response);
		}
		finally
		{
			$this->releaseOperationLock($lockName);
		}
	}

	/** @return list<string> */
	protected function fetchCurrencyCodes(): array
	{
		return $this->repository->getCodes();
	}

	/**
	 * @param list<string> $currencyCodes
	 * @return array<string, array{CURRENCY: string, BASE: bool}>
	 */
	protected function fetchCurrencyDeletionStates(array $currencyCodes): array
	{
		return $this->repository->getDeletionStates($currencyCodes);
	}

	/**
	 * @param list<string> $currencyCodes
	 * @return array<string, Result>
	 */
	protected function deleteCurrencies(array $currencyCodes): array
	{
		return $this->mutationService->deleteBatch($currencyCodes);
	}

	protected function acquireOperationLock(string $lockName, int $timeout): bool
	{
		return Application::getConnection()->lock($lockName, $timeout);
	}

	protected function releaseOperationLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	/**
	 * @return array{
	 *     context: array<string, mixed>,
	 *     itemErrors: list<array{currencyCode: string, message: string}>
	 * }|null
	 */
	protected function readOperationState(string $storageName): ?array
	{
		[$registryName, $operationKey] = $this->splitStorageName($storageName);
		$storage = Application::getInstance()->getLocalSession($registryName);
		$state = $storage['operations'][$operationKey] ?? null;

		return is_array($state) ? $state : null;
	}

	/**
	 * @param array{
	 *     context: array<string, mixed>,
	 *     itemErrors: list<array{currencyCode: string, message: string}>
	 * } $state
	 */
	protected function writeOperationState(string $storageName, array $state): void
	{
		[$registryName, $operationKey] = $this->splitStorageName($storageName);
		$storage = Application::getInstance()->getLocalSession($registryName);
		$operations = is_array($storage['operations'] ?? null) ? $storage['operations'] : [];
		$operations[$operationKey] = $state;
		$storage->set('operations', $operations);
		Application::getInstance()->getSessionLocalStorageManager()->save();
	}

	protected function clearOperationState(string $storageName): void
	{
		[$registryName, $operationKey] = $this->splitStorageName($storageName);
		$storage = Application::getInstance()->getLocalSession($registryName);
		$operations = is_array($storage['operations'] ?? null) ? $storage['operations'] : [];
		unset($operations[$operationKey]);
		if ($operations === [])
		{
			unset($storage['operations']);
		}
		else
		{
			$storage->set('operations', $operations);
		}
		Application::getInstance()->getSessionLocalStorageManager()->save();
	}

	protected function prepareOperationStorage(string $storageName): bool
	{
		[$registryName] = $this->splitStorageName($storageName);
		$storage = Application::getInstance()->getLocalSession($registryName);
		$operations = is_array($storage['operations'] ?? null) ? $storage['operations'] : [];
		$retainedOperations = [];
		$activeOperationCount = 0;
		foreach ($operations as $operationKey => $state)
		{
			if ($this->isOperationStateExpired($state))
			{
				continue;
			}

			try
			{
				[$context] = $this->restoreState($state);
			}
			catch (\InvalidArgumentException | \UnexpectedValueException)
			{
				continue;
			}

			$retainedOperations[$operationKey] = $state;
			if (!$context->isTerminal())
			{
				$activeOperationCount++;
			}
		}

		if ($retainedOperations !== $operations)
		{
			if ($retainedOperations === [])
			{
				unset($storage['operations']);
			}
			else
			{
				$storage->set('operations', $retainedOperations);
			}
			Application::getInstance()->getSessionLocalStorageManager()->save();
		}

		return $activeOperationCount < self::MAX_ACTIVE_OPERATIONS;
	}

	protected function isActiveOperationState(mixed $state): bool
	{
		$context = is_array($state) && is_array($state['context'] ?? null)
			? $state['context']
			: null;
		if (
			$context === null
			|| in_array(
				$context['state'] ?? null,
				[BatchDeletionContext::STATE_COMPLETED, BatchDeletionContext::STATE_CANCELLED],
				true,
			)
		)
		{
			return false;
		}

		return !$this->isOperationStateExpired($state);
	}

	protected function isOperationStateExpired(mixed $state): bool
	{
		$updatedAt = is_array($state) && is_array($state['context'] ?? null)
			? ($state['context']['updatedAt'] ?? null)
			: null;

		return !is_int($updatedAt)
			|| $updatedAt <= $this->getCurrentTimestamp() - self::ACTIVE_OPERATION_TTL;
	}

	protected function getCurrentTimestamp(): int
	{
		return time();
	}

	private function buildStorageName(int $userId, string $gridId, string $processToken): string
	{
		return md5($userId . ':' . $gridId) . ':' . hash('sha256', $processToken);
	}

	private function buildLockName(string $storageName): string
	{
		[$registryName] = $this->splitStorageName($storageName);

		return self::LOCK_PREFIX . $registryName;
	}

	/** @return array{0: string, 1: string} */
	private function splitStorageName(string $storageName): array
	{
		$parts = explode(':', $storageName, 2);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '')
		{
			throw new \InvalidArgumentException('Invalid batch deletion storage name');
		}

		return [$parts[0], $parts[1]];
	}

	private function isValidProcessToken(string $processToken): bool
	{
		return strlen($processToken) <= self::MAX_PROCESS_TOKEN_LENGTH
			&& preg_match(self::PROCESS_TOKEN_PATTERN, $processToken) === 1;
	}

	/**
	 * @param array{
	 *     context: array<string, mixed>,
	 *     itemErrors: list<array{currencyCode: string, message: string}>
	 * } $state
	 * @return array{0: BatchDeletionContext, 1: list<array{currencyCode: string, message: string}>}
	 */
	private function restoreState(array $state): array
	{
		$contextData = $state['context'] ?? null;
		$itemErrors = $state['itemErrors'] ?? null;
		if (!is_array($contextData) || !is_array($itemErrors))
		{
			throw new \UnexpectedValueException('Invalid stored batch deletion state');
		}

		foreach ($itemErrors as $itemError)
		{
			if (
				!is_array($itemError)
				|| !is_string($itemError['currencyCode'] ?? null)
				|| !is_string($itemError['message'] ?? null)
			)
			{
				throw new \UnexpectedValueException('Invalid stored batch deletion item error');
			}
		}

		return [BatchDeletionContext::fromArray($contextData), array_values($itemErrors)];
	}

	/** @param list<array{currencyCode: string, message: string}> $itemErrors */
	private function buildState(BatchDeletionContext $context, array $itemErrors): array
	{
		return [
			'context' => $context->toArray(),
			'itemErrors' => $context->isTerminal() ? [] : $itemErrors,
		];
	}

	/** @param list<array{currencyCode: string, message: string}> $itemErrors */
	private function buildResponse(
		BatchDeletionContext $context,
		array $itemErrors,
		bool $includeNextAction = false,
		bool $completed = false,
	): array
	{
		$counters = $context->getCounters();
		$response = [
			'STATUS' => $completed ? 'COMPLETED' : 'PROGRESS',
			'PROCESSED_ITEMS' => $counters['processed'],
			'TOTAL_ITEMS' => $counters['total'],
			'SUCCESSFUL_ITEMS' => $counters['success'],
			'FAILED_ITEMS' => $counters['failed'],
			'ITEM_ERRORS' => $itemErrors,
		];

		if ($includeNextAction && !$completed)
		{
			$response['NEXT_ACTION'] = 'process';
		}

		return $response;
	}

	private function validateContextIdentity(
		BatchDeletionContext $context,
		int $userId,
		string $gridId,
		string $processToken,
	): ?Result
	{
		if (
			$context->getUserId() !== $userId
			|| $context->getGridId() !== $gridId
			|| !hash_equals($context->getProcessToken(), $processToken)
		)
		{
			return $this->createErrorResult(
				self::ERROR_CONTEXT_MISMATCH,
				'CRM_CURRENCY_BATCH_CONTEXT_MISMATCH',
			);
		}

		return null;
	}

	private function extractSafeErrorMessage(Result $result): string
	{
		if ($result->getError()?->getCode() === CurrencyMutationService::ERROR_ACCOUNTING_CURRENCY_DELETE)
		{
			return $this->getMessage('CRM_CURRENCY_BATCH_ACCOUNTING_PROTECTED');
		}

		$message = (string)($result->getErrorMessages()[0] ?? '');
		if ($message === '' || preg_match('//u', $message) !== 1)
		{
			return $this->getMessage('CRM_CURRENCY_BATCH_DELETE_FAILED');
		}

		return $message;
	}

	private function createSuccessResult(array $data): Result
	{
		return (new Result())->setData($data);
	}

	private function createErrorResult(string $errorCode, string $messageCode): Result
	{
		return (new Result())->addError(new Error($this->getMessage($messageCode), $errorCode));
	}

	private function getMessage(string $messageCode): string
	{
		return (string)Loc::getMessage($messageCode);
	}
}
