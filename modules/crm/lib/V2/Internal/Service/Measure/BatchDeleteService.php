<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Measure;

use Bitrix\Crm\V2\Internal\Integration\Catalog\Measure\MeasureGateway;
use Bitrix\Crm\V2\Internal\Model\Measure\BatchDeleteState;
use Bitrix\Main\Application;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class BatchDeleteService
{
	private const BATCH_SIZE = 20;
	private const STORAGE_NAME = 'crm_measure_batch_delete';

	private readonly MeasureGateway $gateway;
	private readonly MeasureMutationService $mutationService;
	private readonly SessionLocalStorage $storage;

	public function __construct(
		?MeasureGateway $gateway = null,
		?MeasureMutationService $mutationService = null,
		?SessionLocalStorage $storage = null,
	)
	{
		$this->gateway = $gateway ?? new MeasureGateway();
		$this->mutationService = $mutationService ?? new MeasureMutationService($this->gateway);
		$this->storage = $storage ?? Application::getInstance()->getLocalSession(self::STORAGE_NAME);
	}

	/**
	 * @param array<int, mixed> $ids
	 */
	public function delete(int $userId, string $processToken, string $scope, array $ids): Result
	{
		$validationResult = $this->validateProcess($userId, $processToken);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$state = $this->load($userId, $processToken);
		if ($state === null)
		{
			$stateResult = $this->createState($scope, $ids);
			if (!$stateResult->isSuccess())
			{
				return $stateResult;
			}

			$state = $stateResult->getData()['state'];
		}

		$batch = $this->getBatch($state);
		foreach ($batch as $id)
		{
			$state->processed++;
			$deleteResult = $this->mutationService->delete($id);
			if ($deleteResult->isSuccess())
			{
				$state->deleted++;
				continue;
			}

			foreach ($deleteResult->getErrors() as $error)
			{
				$state->errors[] = [
					'id' => $id,
					'message' => $error->getMessage(),
				];
			}
		}

		if ($state->scope === 'selected')
		{
			$state->selectedOffset += count($batch);
		}
		elseif ($batch !== [])
		{
			$state->lastId = (int)end($batch);
		}

		$this->save($userId, $processToken, $state);
		$hasMore = $this->hasMore($state);

		return $this->createDtoResult($state, $hasMore ? 'PROGRESS' : 'COMPLETED', !$hasMore);
	}

	public function cancel(int $userId, string $processToken): Result
	{
		return $this->finish($userId, $processToken);
	}

	public function finalize(int $userId, string $processToken): Result
	{
		return $this->finish($userId, $processToken);
	}

	private function finish(int $userId, string $processToken): Result
	{
		$validationResult = $this->validateProcess($userId, $processToken);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$state = $this->load($userId, $processToken);
		if ($state === null)
		{
			return (new Result())->addError(new Error('Batch process was not found.', 'PROCESS_NOT_FOUND'));
		}

		unset($this->storage[$this->getStorageKey($userId, $processToken)]);

		return $this->createDtoResult($state, 'COMPLETED', false);
	}

	private function validateProcess(int $userId, string $processToken): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError(new Error('Invalid user session.', 'INVALID_SESSION'));
		}

		if (trim($processToken) === '' || mb_strlen($processToken) > 128)
		{
			return $result->addError(new Error('Invalid process token.', 'INVALID_ARGUMENT'));
		}

		return $result;
	}

	/**
	 * @param array<int, mixed> $ids
	 */
	private function createState(string $scope, array $ids): Result
	{
		$result = new Result();
		if (!in_array($scope, ['selected', 'all'], true))
		{
			return $result->addError(new Error('Invalid deletion scope.', 'INVALID_ARGUMENT'));
		}

		$normalizedIds = [];
		if ($scope === 'selected')
		{
			foreach ($ids as $id)
			{
				$normalizedId = $this->normalizeMeasureId($id);
				if ($normalizedId === null)
				{
					return $result->addError(new Error('Invalid measure ID.', 'INVALID_ARGUMENT'));
				}

				$normalizedIds[$normalizedId] = $normalizedId;
			}

			$normalizedIds = array_values($normalizedIds);
			if ($normalizedIds === [])
			{
				return $result->addError(new Error('Measure IDs are required.', 'INVALID_ARGUMENT'));
			}
		}

		$upperBound = $scope === 'all' ? $this->gateway->getMaxId() : null;
		$total = $scope === 'selected'
			? count($normalizedIds)
			: $this->gateway->getCountAtOrBelow($upperBound ?? 0)
		;

		$result->setData([
			'state' => new BatchDeleteState(
				scope: $scope,
				ids: $normalizedIds,
				selectedOffset: 0,
				lastId: 0,
				upperBound: $upperBound,
				total: $total,
			),
		]);

		return $result;
	}

	private function normalizeMeasureId(mixed $id): ?int
	{
		if (is_int($id))
		{
			return $id > 0 ? $id : null;
		}

		if (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1)
		{
			return null;
		}

		$maxInteger = (string)PHP_INT_MAX;
		if (
			strlen($id) > strlen($maxInteger)
			|| (strlen($id) === strlen($maxInteger) && strcmp($id, $maxInteger) > 0)
		)
		{
			return null;
		}

		return (int)$id;
	}

	/**
	 * @return int[]
	 */
	private function getBatch(BatchDeleteState $state): array
	{
		if ($state->scope === 'selected')
		{
			return array_slice($state->ids, $state->selectedOffset, self::BATCH_SIZE);
		}

		return $this->gateway->getNextIds($state->lastId, $state->upperBound ?? 0, self::BATCH_SIZE);
	}

	private function hasMore(BatchDeleteState $state): bool
	{
		if ($state->scope === 'selected')
		{
			return $state->selectedOffset < count($state->ids);
		}

		return $this->gateway->getNextIds($state->lastId, $state->upperBound ?? 0, 1) !== [];
	}

	private function load(int $userId, string $processToken): ?BatchDeleteState
	{
		$key = $this->getStorageKey($userId, $processToken);
		if (!isset($this->storage[$key]) || !is_array($this->storage[$key]))
		{
			return null;
		}

		return BatchDeleteState::fromArray($this->storage[$key]);
	}

	private function save(int $userId, string $processToken, BatchDeleteState $state): void
	{
		$this->storage->set($this->getStorageKey($userId, $processToken), $state->toArray());
	}

	private function getStorageKey(int $userId, string $processToken): string
	{
		return $userId . ':' . $processToken;
	}

	private function createDtoResult(BatchDeleteState $state, string $status, bool $finalize): Result
	{
		$result = new Result();
		$result->setData([
			'STATUS' => $status,
			'PROCESSED_ITEMS' => $state->processed,
			'TOTAL_ITEMS' => $state->total,
			'DELETED_ITEMS' => $state->deleted,
			'ERRORS' => $state->errors,
			'WARNING' => '',
			'SUMMARY' => '',
			'FINALIZE' => $finalize,
		]);

		return $result;
	}
}
