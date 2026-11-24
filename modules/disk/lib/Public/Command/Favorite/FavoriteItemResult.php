<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Favorite;

/**
 * Outcome of adding or removing the favorite mark of one object: what happened and the actual state after the step.
 */
final class FavoriteItemResult
{
	public const STATUS_CHANGED = 'changed';
	public const STATUS_UNCHANGED = 'unchanged';
	public const STATUS_ACCESS_DENIED = 'accessDenied';
	public const STATUS_NOT_FOUND = 'notFound';
	public const STATUS_FAILED = 'failed';

	/**
	 * @param bool|null $isFavorite null only when the step failed and the state is unknown
	 */
	private function __construct(
		public readonly int $objectId,
		public readonly string $status,
		public readonly ?bool $isFavorite,
	)
	{
	}

	public static function changed(int $objectId, bool $isFavorite): self
	{
		return new self($objectId, self::STATUS_CHANGED, $isFavorite);
	}

	public static function unchanged(int $objectId, bool $isFavorite): self
	{
		return new self($objectId, self::STATUS_UNCHANGED, $isFavorite);
	}

	public static function accessDenied(int $objectId): self
	{
		return new self($objectId, self::STATUS_ACCESS_DENIED, false);
	}

	public static function notFound(int $objectId): self
	{
		return new self($objectId, self::STATUS_NOT_FOUND, false);
	}

	public static function failed(int $objectId): self
	{
		return new self($objectId, self::STATUS_FAILED, null);
	}

	/**
	 * @return array{objectId: int, status: string, isFavorite: bool|null}
	 */
	public function toArray(): array
	{
		return [
			'objectId' => $this->objectId,
			'status' => $this->status,
			'isFavorite' => $this->isFavorite,
		];
	}
}
