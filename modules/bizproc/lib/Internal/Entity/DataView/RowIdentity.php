<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\DataView;

final class RowIdentity
{
	public const WORKFLOW_SYSTEM = 'dataview';

	public const WORKFLOW_FROZEN = 'dataview_manual';

	public const DOCUMENT_PREFIX = 'bizproc:dataview:';

	public function __construct(
		public readonly int $leftRecordId,
		public readonly int $rightRecordId,
	) {
	}

	public static function documentId(int $dataViewId): string
	{
		return self::DOCUMENT_PREFIX . $dataViewId;
	}

	public function serialize(): string
	{
		return $this->leftRecordId . ':' . $this->rightRecordId;
	}

	public static function fromSerialized(?string $code): ?self
	{
		if ($code === null || $code === '')
		{
			return null;
		}

		$parts = explode(':', $code);
		if (count($parts) !== 2)
		{
			return null;
		}

		[$left, $right] = $parts;
		if (!self::isPositiveIntString($left) || !self::isPositiveIntString($right))
		{
			return null;
		}

		return new self((int)$left, (int)$right);
	}

	public function toArray(): array
	{
		return ['left' => $this->leftRecordId, 'right' => $this->rightRecordId];
	}

	public static function fromArray(array $mark): ?self
	{
		if (isset($mark['left'], $mark['right']))
		{
			return new self((int)$mark['left'], (int)$mark['right']);
		}

		return null;
	}

	public function equals(self $other): bool
	{
		return $this->leftRecordId === $other->leftRecordId
			&& $this->rightRecordId === $other->rightRecordId;
	}

	private static function isPositiveIntString(string $value): bool
	{
		return preg_match('/^[0-9]+$/D', $value) === 1;
	}
}
