<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Location\BatchDelete;

final class BatchDeleteState
{
	public const VERSION = 1;
	public const GRID_ID = 'CRM_LOC_LIST';
	public const MODE_SELECTED = 'selected';
	public const MODE_ALL = 'all';
	public const OUTCOME_SUCCESS = 'success';
	public const OUTCOME_NOT_FOUND = 'not_found';
	public const OUTCOME_ERROR = 'error';

	public function __construct(
		public readonly string $token,
		public readonly string $mode,
		public readonly array $selectedIds,
		public readonly array $normalizedFilter,
		public readonly int $upperBoundId,
		public int $cursorId,
		public array $currentBatch,
		public array $batchOutcomes,
		public readonly int $totalItems,
		public int $processedItems,
		public int $successItems,
		public int $failedItems,
		public array $errorDetails,
		public int $updatedAt,
		public int $leaseExpiresAt,
		public bool $legacyPathResetPending = false,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		if (
			($data['version'] ?? null) !== self::VERSION
			|| ($data['gridId'] ?? null) !== self::GRID_ID
			|| !is_string($data['token'] ?? null)
			|| !in_array($data['mode'] ?? null, [self::MODE_SELECTED, self::MODE_ALL], true)
		)
		{
			return null;
		}

		return new self(
			$data['token'],
			$data['mode'],
			self::normalizeIds($data['selectedIds'] ?? []),
			is_array($data['normalizedFilter'] ?? null) ? $data['normalizedFilter'] : [],
			max(0, (int)($data['upperBoundId'] ?? 0)),
			max(0, (int)($data['cursorId'] ?? 0)),
			self::normalizeIds($data['currentBatch'] ?? []),
			self::normalizeOutcomes($data['batchOutcomes'] ?? []),
			max(0, (int)($data['totalItems'] ?? 0)),
			max(0, (int)($data['processedItems'] ?? 0)),
			max(0, (int)($data['successItems'] ?? 0)),
			max(0, (int)($data['failedItems'] ?? 0)),
			self::normalizeErrorDetails($data['errorDetails'] ?? []),
			max(0, (int)($data['updatedAt'] ?? 0)),
			max(0, (int)($data['leaseExpiresAt'] ?? 0)),
			(bool)($data['legacyPathResetPending'] ?? false),
		);
	}

	public function toArray(): array
	{
		return [
			'version' => self::VERSION,
			'token' => $this->token,
			'gridId' => self::GRID_ID,
			'mode' => $this->mode,
			'selectedIds' => $this->selectedIds,
			'normalizedFilter' => $this->normalizedFilter,
			'upperBoundId' => $this->upperBoundId,
			'cursorId' => $this->cursorId,
			'currentBatch' => $this->currentBatch,
			'batchOutcomes' => $this->batchOutcomes,
			'totalItems' => $this->totalItems,
			'processedItems' => $this->processedItems,
			'successItems' => $this->successItems,
			'failedItems' => $this->failedItems,
			'errorDetails' => $this->errorDetails,
			'updatedAt' => $this->updatedAt,
			'leaseExpiresAt' => $this->leaseExpiresAt,
			'legacyPathResetPending' => $this->legacyPathResetPending,
		];
	}

	public function refreshLease(int $now, int $leaseTtl): void
	{
		$this->updatedAt = $now;
		$this->leaseExpiresAt = $now + $leaseTtl;
	}

	public function recordOutcome(int $rootId, string $outcome): void
	{
		if (!isset($this->batchOutcomes[$rootId]))
		{
			$this->batchOutcomes[$rootId] = $outcome;
		}
	}

	public function addErrorDetail(int $rootId, string $code, string $message, int $limit): void
	{
		if (count($this->errorDetails) >= $limit)
		{
			return;
		}

		$this->errorDetails[] = [
			'rootId' => $rootId,
			'code' => $code,
			'message' => $message,
		];
	}

	public function foldCurrentBatch(bool $includeIncomplete = false): void
	{
		if (!$includeIncomplete)
		{
			foreach ($this->currentBatch as $rootId)
			{
				if (!isset($this->batchOutcomes[$rootId]))
				{
					return;
				}
			}
		}

		foreach ($this->currentBatch as $rootId)
		{
			$outcome = $this->batchOutcomes[$rootId] ?? null;
			if ($outcome === null)
			{
				continue;
			}

			$this->processedItems++;
			if ($outcome === self::OUTCOME_ERROR)
			{
				$this->failedItems++;
			}
			else
			{
				$this->successItems++;
			}
		}

		if (!$includeIncomplete && $this->currentBatch !== [])
		{
			$this->cursorId = max($this->currentBatch);
		}

		$this->currentBatch = [];
		$this->batchOutcomes = [];
	}

	private static function normalizeIds(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

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

	private static function normalizeOutcomes(mixed $outcomes): array
	{
		if (!is_array($outcomes))
		{
			return [];
		}

		$normalized = [];
		foreach ($outcomes as $id => $outcome)
		{
			$id = (int)$id;
			if (
				$id > 0
				&& in_array(
					$outcome,
					[self::OUTCOME_SUCCESS, self::OUTCOME_NOT_FOUND, self::OUTCOME_ERROR],
					true,
				)
			)
			{
				$normalized[$id] = $outcome;
			}
		}

		return $normalized;
	}

	private static function normalizeErrorDetails(mixed $details): array
	{
		if (!is_array($details))
		{
			return [];
		}

		$normalized = [];
		foreach ($details as $detail)
		{
			if (!is_array($detail) || (int)($detail['rootId'] ?? 0) <= 0)
			{
				continue;
			}

			$normalized[] = [
				'rootId' => (int)$detail['rootId'],
				'code' => (string)($detail['code'] ?? ''),
				'message' => (string)($detail['message'] ?? ''),
			];
		}

		return $normalized;
	}
}
