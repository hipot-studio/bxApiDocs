<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\Measure;

/**
 * @internal
 */
final class BatchDeleteState
{
	/**
	 * @param int[] $ids
	 * @param array<int, array{id: int, message: string}> $errors
	 */
	public function __construct(
		public readonly string $scope,
		public readonly array $ids,
		public int $selectedOffset,
		public int $lastId,
		public readonly ?int $upperBound,
		public readonly int $total,
		public int $processed = 0,
		public int $deleted = 0,
		public array $errors = [],
	)
	{
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): ?self
	{
		$scope = $data['scope'] ?? null;
		$ids = $data['ids'] ?? null;
		$errors = $data['errors'] ?? null;
		if (!is_string($scope) || !is_array($ids) || !is_array($errors))
		{
			return null;
		}

		return new self(
			scope: $scope,
			ids: array_map('intval', $ids),
			selectedOffset: (int)($data['selectedOffset'] ?? 0),
			lastId: (int)($data['lastId'] ?? 0),
			upperBound: isset($data['upperBound']) ? (int)$data['upperBound'] : null,
			total: (int)($data['total'] ?? 0),
			processed: (int)($data['processed'] ?? 0),
			deleted: (int)($data['deleted'] ?? 0),
			errors: $errors,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'scope' => $this->scope,
			'ids' => $this->ids,
			'selectedOffset' => $this->selectedOffset,
			'lastId' => $this->lastId,
			'upperBound' => $this->upperBound,
			'total' => $this->total,
			'processed' => $this->processed,
			'deleted' => $this->deleted,
			'errors' => $this->errors,
		];
	}
}
