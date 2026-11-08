<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Category\Deal;

final class BatchDeleteState
{
	private const ITEM_ERROR_LIMIT = 50;
	private const SCOPE_SELECTED = 'selected';
	private const SCOPE_ALL = 'all';

	private function __construct(
		private readonly int $ownerUserId,
		private readonly string $scope,
		private readonly string $selectionHash,
		private readonly int $totalCount,
		private readonly ?int $upperId,
		private int $lastId = -1,
		private int $processedCount = 0,
		private int $successCount = 0,
		private int $errorCount = 0,
		private array $itemErrors = [],
	)
	{
	}

	public static function create(
		int $ownerUserId,
		string $scope,
		string $selectionHash,
		int $totalCount,
		?int $upperId,
	): self
	{
		return new self($ownerUserId, $scope, $selectionHash, $totalCount, $upperId);
	}

	public static function fromArray(array $data): ?self
	{
		$ownerUserId = $data['ownerUserId'] ?? null;
		$scope = $data['scope'] ?? null;
		$selectionHash = $data['selectionHash'] ?? null;
		$totalCount = $data['totalCount'] ?? null;
		$upperId = $data['upperId'] ?? null;
		$lastId = $data['lastId'] ?? null;
		$processedCount = $data['processedCount'] ?? null;
		$successCount = $data['successCount'] ?? null;
		$itemErrors = $data['itemErrors'] ?? null;
		$isLegacyState = !array_key_exists('errorCount', $data);
		$errorCount = $isLegacyState
			? (is_array($itemErrors) ? count($itemErrors) : null)
			: ($data['errorCount'] ?? null)
		;
		if (
			!is_int($ownerUserId)
			|| $ownerUserId <= 0
			|| !in_array($scope, [self::SCOPE_SELECTED, self::SCOPE_ALL], true)
			|| !is_string($selectionHash)
			|| preg_match('/^[a-f0-9]{64}$/D', $selectionHash) !== 1
			|| !is_int($totalCount)
			|| $totalCount < 0
			|| !self::isValidUpperId($scope, $upperId)
			|| !is_int($lastId)
			|| $lastId < -1
			|| !is_int($processedCount)
			|| $processedCount < 0
			|| $processedCount > $totalCount
			|| !is_int($successCount)
			|| $successCount < 0
			|| $successCount > $processedCount
			|| !is_int($errorCount)
			|| $errorCount < 0
			|| $errorCount > $processedCount
			|| !is_array($itemErrors)
			|| (!$isLegacyState && count($itemErrors) !== min($errorCount, self::ITEM_ERROR_LIMIT))
		)
		{
			return null;
		}

		foreach ($itemErrors as $itemError)
		{
			if (!self::isValidItemError($itemError))
			{
				return null;
			}
		}
		if ($processedCount !== $successCount + $errorCount)
		{
			return null;
		}
		if (($processedCount === 0 && $lastId !== -1) || ($processedCount > 0 && $lastId < 0))
		{
			return null;
		}
		if ($isLegacyState)
		{
			$itemErrors = array_slice($itemErrors, 0, self::ITEM_ERROR_LIMIT);
		}

		return new self(
			$ownerUserId,
			$scope,
			$selectionHash,
			$totalCount,
			$upperId,
			$lastId,
			$processedCount,
			$successCount,
			$errorCount,
			$itemErrors,
		);
	}

	public function toArray(): array
	{
		return [
			'ownerUserId' => $this->ownerUserId,
			'scope' => $this->scope,
			'selectionHash' => $this->selectionHash,
			'totalCount' => $this->totalCount,
			'upperId' => $this->upperId,
			'lastId' => $this->lastId,
			'processedCount' => $this->processedCount,
			'successCount' => $this->successCount,
			'errorCount' => $this->errorCount,
			'itemErrors' => $this->itemErrors,
		];
	}

	public function getOwnerUserId(): int
	{
		return $this->ownerUserId;
	}

	public function getScope(): string
	{
		return $this->scope;
	}

	public function getSelectionHash(): string
	{
		return $this->selectionHash;
	}

	public function getTotalCount(): int
	{
		return $this->totalCount;
	}

	public function getUpperId(): ?int
	{
		return $this->upperId;
	}

	public function getLastId(): int
	{
		return $this->lastId;
	}

	public function isComplete(): bool
	{
		return $this->processedCount >= $this->totalCount;
	}

	public function finishSuccess(int $id): void
	{
		$this->lastId = $id;
		$this->processedCount++;
		$this->successCount++;
	}

	public function finishError(int $id, string $name, string $code, string $message): void
	{
		$this->lastId = $id;
		$this->processedCount++;
		$this->errorCount++;
		if (count($this->itemErrors) < self::ITEM_ERROR_LIMIT)
		{
			$this->itemErrors[] = [
				'id' => $id,
				'name' => $name,
				'code' => $code,
				'message' => $message,
			];
		}
	}

	public function getProcessedItemsCount(): int
	{
		return $this->processedCount;
	}

	public function getSuccessCount(): int
	{
		return $this->successCount;
	}

	public function getErrorCount(): int
	{
		return $this->errorCount;
	}

	public function getItemErrors(): array
	{
		return $this->itemErrors;
	}

	private static function isValidUpperId(string $scope, mixed $upperId): bool
	{
		return $scope === self::SCOPE_ALL
			? is_int($upperId) && $upperId >= 0
			: $upperId === null
		;
	}

	private static function isValidItemError(mixed $itemError): bool
	{
		return
			is_array($itemError)
			&& is_int($itemError['id'] ?? null)
			&& $itemError['id'] >= 0
			&& is_string($itemError['name'] ?? null)
			&& is_string($itemError['code'] ?? null)
			&& is_string($itemError['message'] ?? null)
		;
	}
}
