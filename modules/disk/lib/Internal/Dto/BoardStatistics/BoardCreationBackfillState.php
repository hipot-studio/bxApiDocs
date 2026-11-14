<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\BoardStatistics;

use Bitrix\Disk\Internals\BoardCreationBackfillTable;
use Bitrix\Main\Type\DateTime;

final class BoardCreationBackfillState
{
	public function __construct(
		public readonly int $maxObjectId,
		public readonly ?int $startObjectId,
		public readonly int $nextObjectId,
		public readonly string $status,
		public readonly ?DateTime $maxObjectFixedAt = null,
	)
	{
	}

	/**
	 * @param array{
	 *     MAX_OBJECT_ID: int|string,
	 *     START_OBJECT_ID: int|string|null,
	 *     NEXT_OBJECT_ID: int|string,
	 *     STATUS: string,
	 *     MAX_OBJECT_FIXED_AT?: DateTime|string|null,
	 * } $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			maxObjectId: (int)$row['MAX_OBJECT_ID'],
			startObjectId: $row['START_OBJECT_ID'] === null ? null : (int)$row['START_OBJECT_ID'],
			nextObjectId: (int)$row['NEXT_OBJECT_ID'],
			status: (string)$row['STATUS'],
			maxObjectFixedAt: self::dateTimeOrNull($row['MAX_OBJECT_FIXED_AT'] ?? null),
		);
	}

	public function isCompleted(): bool
	{
		return $this->status === BoardCreationBackfillTable::STATUS_COMPLETED;
	}

	private static function dateTimeOrNull(mixed $value): ?DateTime
	{
		if ($value === null || $value instanceof DateTime)
		{
			return $value;
		}

		return new DateTime((string)$value, 'Y-m-d H:i:s');
	}
}
