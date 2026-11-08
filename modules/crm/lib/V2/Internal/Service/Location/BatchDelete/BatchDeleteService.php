<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Location\BatchDelete;

use Bitrix\Crm\V2\Internal\Integration\Sale\Location\LocationAdapter;
use Bitrix\Crm\V2\Internal\Service\Location\EffectiveScope;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Closure;

Loc::loadMessages(__FILE__);

final class BatchDeleteService
{
	public const BATCH_SIZE = 20;
	public const LEASE_TTL = 120;
	public const RECEIPT_TTL = 120;
	public const ERROR_DETAILS_LIMIT = 10;

	private readonly Closure $clock;

	public function __construct(
		private readonly LocationAdapter $adapter = new LocationAdapter(),
		private readonly BatchDeleteStorage $storage = new BatchDeleteStorage(),
		?Closure $clock = null,
		private readonly string $languageId = LANGUAGE_ID,
	)
	{
		$this->clock = $clock ?? static fn(): int => time();
	}

	public function process(
		string $token,
		?string $mode = null,
		array $ids = [],
		?EffectiveScope $scope = null,
	): Result
	{
		if (!$this->isValidToken($token))
		{
			return $this->error(
				$this->message('CRM_LOCATION_BATCH_DELETE_INVALID_TOKEN', 'Invalid operation token.'),
				'INVALID_SCOPE',
			);
		}

		$now = $this->now();
		$this->storage->purgeExpiredReceipts($now);
		$receipt = $this->storage->loadCompleted($token);
		if ($receipt !== null)
		{
			return $this->success($receipt->result);
		}

		$state = $this->storage->loadState($token);
		if ($state === null)
		{
			if ($mode === null)
			{
				return $this->error(
					$this->message('CRM_LOCATION_BATCH_DELETE_NOT_FOUND', 'Batch delete operation was not found.'),
					'OPERATION_NOT_FOUND',
				);
			}

			$markerResult = $this->releaseExpiredMarkerOrReject($token, $now);
			if (!$markerResult->isSuccess())
			{
				return $markerResult;
			}

			$state = $this->createState($token, $mode, $ids, $scope, $now);
			if ($state === null)
			{
				return $this->error(
					$this->message('CRM_LOCATION_BATCH_DELETE_INVALID_SCOPE', 'Batch delete scope is invalid.'),
					'INVALID_SCOPE',
				);
			}

			$this->storage->saveState($state);
		}
		else
		{
			$state->refreshLease($now, self::LEASE_TTL);
			$this->storage->saveState($state);
		}

		return $this->processState($state);
	}

	public function cancel(string $token): Result
	{
		if (!$this->isValidToken($token))
		{
			return $this->error(
				$this->message('CRM_LOCATION_BATCH_DELETE_INVALID_TOKEN', 'Invalid operation token.'),
				'INVALID_SCOPE',
			);
		}

		$now = $this->now();
		$this->storage->purgeExpiredReceipts($now);
		$receipt = $this->storage->loadCompleted($token);
		if ($receipt !== null)
		{
			return $this->success($receipt->result);
		}

		$state = $this->storage->loadState($token);
		if ($state === null)
		{
			return $this->success(
				$this->buildResponse(
					0,
					0,
					0,
					0,
					[],
					$this->message('CRM_LOCATION_BATCH_DELETE_CANCELED', 'Operation was canceled.'),
				),
			);
		}

		$state->foldCurrentBatch(true);
		$result = $this->buildFinalResponse(
			$state,
			$this->message('CRM_LOCATION_BATCH_DELETE_CANCELED', 'Operation was canceled.'),
		);
		$this->storeCompleted($state, $result, $now);

		return $this->success($result);
	}

	private function processState(BatchDeleteState $state): Result
	{
		$this->finalizePendingLegacyPathReset($state);

		if ($state->currentBatch === [])
		{
			$state->currentBatch = $this->findNextRoots($state, self::BATCH_SIZE + 1);
			if ($state->currentBatch === [])
			{
				return $this->completeState($state);
			}

			$state->batchOutcomes = [];
			$state->refreshLease($this->now(), self::LEASE_TTL);
			$this->storage->saveState($state);
		}

		$batchRoots = array_slice($state->currentBatch, 0, self::BATCH_SIZE);
		$hasMore = count($state->currentBatch) > count($batchRoots);
		try
		{
			foreach ($batchRoots as $rootId)
			{
				if (isset($state->batchOutcomes[$rootId]))
				{
					continue;
				}

				$requiresLegacyPathReset = $this->adapter->requiresLegacyPathReset($rootId);
				if ($requiresLegacyPathReset === null)
				{
					$state->recordOutcome($rootId, BatchDeleteState::OUTCOME_NOT_FOUND);
				}
				else
				{
					if ($requiresLegacyPathReset && !$state->legacyPathResetPending)
					{
						$state->legacyPathResetPending = true;
						$this->storage->checkpointState($state);
					}

					$deleteResult = $this->adapter->deleteRoot($rootId, true);
					if ($deleteResult->isSuccess())
					{
						$state->recordOutcome($rootId, BatchDeleteState::OUTCOME_SUCCESS);
					}
					else
					{
						$state->recordOutcome($rootId, BatchDeleteState::OUTCOME_ERROR);
						$error = $deleteResult->getError();
						$state->addErrorDetail(
							$rootId,
							(string)($error?->getCode() ?? 'LOCATION_DELETE_FAILED'),
							(string)($error?->getMessage() ?? 'Location cannot be deleted.'),
							self::ERROR_DETAILS_LIMIT,
						);
					}
				}

				$now = $this->now();
				if ($state->leaseExpiresAt - $now <= intdiv(self::LEASE_TTL, 2))
				{
					$state->refreshLease($now, self::LEASE_TTL);
					$this->storage->checkpointState($state);
				}
			}
		}
		finally
		{
			$this->finalizePendingLegacyPathReset($state);
		}

		$state->currentBatch = $batchRoots;
		$state->foldCurrentBatch();
		$state->refreshLease($this->now(), self::LEASE_TTL);
		$this->storage->saveState($state);

		if ($hasMore)
		{
			return $this->success($this->buildProgressResponse($state));
		}

		return $this->completeState($state);
	}

	private function createState(
		string $token,
		string $mode,
		array $ids,
		?EffectiveScope $scope,
		int $now,
	): ?BatchDeleteState
	{
		if ($scope === null || !in_array($mode, [BatchDeleteState::MODE_SELECTED, BatchDeleteState::MODE_ALL], true))
		{
			return null;
		}

		$selectedIds = [];
		$normalizedFilter = [];
		$upperBoundId = 0;
		if ($mode === BatchDeleteState::MODE_SELECTED)
		{
			$ids = $this->normalizeIds($ids);
			if ($ids === [])
			{
				return null;
			}

			$selectedIds = $this->fetchIds(
				new EffectiveScope(
					array_merge($scope->filter, ['@ID' => $ids]),
					['ID' => 'ASC'],
					['ID'],
					$scope->runtime,
				),
			);
		}
		else
		{
			$normalizedFilter = $scope->filter;
			$upperBoundId = $this->adapter->fetchMaxId($scope);
			$countFilter = $scope->filter;
			$countFilter['<=ID'] = $upperBoundId;
			$totalItems = $this->adapter->fetchCount(
				new EffectiveScope(
					$countFilter,
					$scope->sort,
					$scope->select,
					$scope->runtime,
				),
			);
		}

		$totalItems = $mode === BatchDeleteState::MODE_SELECTED ? count($selectedIds) : $totalItems;

		return new BatchDeleteState(
			$token,
			$mode,
			$selectedIds,
			$normalizedFilter,
			$upperBoundId,
			0,
			[],
			[],
			$totalItems,
			0,
			0,
			0,
			[],
			$now,
			$now + self::LEASE_TTL,
		);
	}

	private function releaseExpiredMarkerOrReject(string $token, int $now): Result
	{
		$marker = $this->storage->getActiveMarker();
		if ($marker === null)
		{
			return new Result();
		}

		if ($marker['token'] === $token)
		{
			$this->storage->clearActiveMarker();

			return new Result();
		}

		if ($marker['leaseExpiresAt'] > $now)
		{
			return $this->error(
				$this->message(
					'CRM_LOCATION_BATCH_DELETE_ALREADY_RUNNING',
					'Another batch delete operation is already running.',
				),
				'OPERATION_ALREADY_RUNNING',
			);
		}

		$expiredState = $this->storage->loadState($marker['token']);
		if ($expiredState !== null)
		{
			$expiredState->foldCurrentBatch(true);
			$this->storeCompleted(
				$expiredState,
				$this->buildFinalResponse(
					$expiredState,
					$this->message(
						'CRM_LOCATION_BATCH_DELETE_LEASE_EXPIRED',
						'Operation lease expired and was canceled.',
					),
				),
				$now,
			);
		}
		else
		{
			$this->storage->clearActiveMarker();
		}

		return new Result();
	}

	private function findNextRoots(BatchDeleteState $state, int $limit): array
	{
		if ($state->mode === BatchDeleteState::MODE_SELECTED)
		{
			return array_slice(
				array_values(
					array_filter(
						$state->selectedIds,
						static fn(int $id): bool => $id > $state->cursorId,
					),
				),
				0,
				$limit,
			);
		}

		if ($state->upperBoundId === 0)
		{
			return [];
		}

		$filter = $state->normalizedFilter;
		$filter['>ID'] = max((int)($filter['>ID'] ?? 0), $state->cursorId);
		$filter['<=ID'] = $state->upperBoundId;

		return $this->adapter->fetchIds($this->createScope($filter), $limit);
	}

	private function createScope(array $filter): EffectiveScope
	{
		return new EffectiveScope(
			$filter,
			['ID' => 'ASC'],
			['ID'],
			$this->adapter->nameRuntime($this->languageId),
		);
	}

	private function fetchIds(EffectiveScope $scope): array
	{
		return $this->normalizeIds($this->adapter->fetchAllIds($scope));
	}

	private function normalizeIds(array $ids): array
	{
		$normalized = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalized[$id] = $id;
			}
		}
		sort($normalized, SORT_NUMERIC);

		return array_values($normalized);
	}

	private function completeState(BatchDeleteState $state): Result
	{
		$result = $this->buildFinalResponse($state);
		$this->storeCompleted($state, $result, $this->now());

		return $this->success($result);
	}

	private function storeCompleted(BatchDeleteState $state, array $result, int $now): void
	{
		$this->finalizePendingLegacyPathReset($state);

		$this->storage->complete(
			$state,
			new BatchDeleteCompletedReceipt(
				$state->token,
				$result,
				$now,
				$now + self::RECEIPT_TTL,
			),
		);
	}

	private function finalizePendingLegacyPathReset(BatchDeleteState $state): void
	{
		if (!$state->legacyPathResetPending)
		{
			return;
		}

		$this->adapter->finalizeBatchDelete();
		$state->legacyPathResetPending = false;
		$this->storage->checkpointState($state);
	}

	private function buildProgressResponse(BatchDeleteState $state): array
	{
		return [
			'STATUS' => 'PROGRESS',
			'PROCESSED_ITEMS' => $state->processedItems,
			'TOTAL_ITEMS' => $state->totalItems,
			'SUCCESS_ITEMS' => $state->successItems,
			'FAILED_ITEMS' => $state->failedItems,
			'SUMMARY' => null,
			'SUMMARY_HTML' => null,
			'WARNING' => null,
		];
	}

	private function buildFinalResponse(BatchDeleteState $state, ?string $warning = null): array
	{
		return $this->buildResponse(
			$state->processedItems,
			$state->totalItems,
			$state->successItems,
			$state->failedItems,
			$state->errorDetails,
			$warning,
		);
	}

	private function buildResponse(
		int $processedItems,
		int $totalItems,
		int $successItems,
		int $failedItems,
		array $errorDetails,
		?string $warning,
	): array
	{
		$summaryParts = [
			$this->message('CRM_LOCATION_BATCH_DELETE_SUMMARY_DONE', 'Deletion completed.'),
		];
		if ($successItems > 0)
		{
			$summaryParts[] = $this->message(
				'CRM_LOCATION_BATCH_DELETE_SUMMARY_SUCCEEDED',
				'Deleted: #COUNT#.',
				['#COUNT#' => (string)$successItems],
			);
		}
		if ($failedItems > 0)
		{
			$summaryParts[] = $this->message(
				'CRM_LOCATION_BATCH_DELETE_SUMMARY_FAILED',
				'Failed to delete: #COUNT#.',
				['#COUNT#' => (string)$failedItems],
			);
		}
		$summary = implode(' ', $summaryParts);
		$summaryHtml = null;
		if ($errorDetails !== [])
		{
			$items = [];
			foreach ($errorDetails as $detail)
			{
				$rootId = (int)$detail['rootId'];
				$code = $this->escape((string)$detail['code']);
				$message = $this->escape((string)$detail['message']);
				$items[] = "<li>#{$rootId} [{$code}]: {$message}</li>";
			}
			$summaryHtml = '<p>' . $this->escape($summary) . '</p><ul>' . implode('', $items) . '</ul>';
			$summary = null;
		}

		return [
			'STATUS' => 'COMPLETED',
			'PROCESSED_ITEMS' => $processedItems,
			'TOTAL_ITEMS' => $totalItems,
			'SUCCESS_ITEMS' => $successItems,
			'FAILED_ITEMS' => $failedItems,
			'SUMMARY' => $summary,
			'SUMMARY_HTML' => $summaryHtml,
			'WARNING' => $warning,
		];
	}

	private function success(array $data): Result
	{
		$result = new Result();
		$result->setData($data);

		return $result;
	}

	private function error(string $message, string $code): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code));

		return $result;
	}

	private function isValidToken(string $token): bool
	{
		return $token !== ''
			&& strlen($token) <= 255
			&& preg_match('/^[^\x00-\x1F\x7F]+$/u', $token) === 1;
	}

	private function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function message(string $code, string $fallback, array $replace = []): string
	{
		$message = Loc::getMessage($code, $replace);

		return is_string($message) && $message !== '' ? $message : strtr($fallback, $replace);
	}

	private function now(): int
	{
		return ($this->clock)();
	}
}
