<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Vibecodeconnector\Internal\Repository\SortDirection;

final class CatalogItemSort
{
	public const FIELD_ID = 'ID';
	public const FIELD_TITLE = 'TITLE';
	public const FIELD_CREATED_AT = 'CREATED_AT';
	public const FIELD_UPDATED_AT = 'UPDATED_AT';
	public const FIELD_IS_PINNED = 'IS_PINNED';
	public const FIELD_PINNED_AT = 'USER_PIN.PINNED_AT';
	public const FIELD_IS_OWNED_BY_USER = 'IS_OWNED_BY_USER';
	public const FIELD_IS_LAST_OPENED = 'IS_LAST_OPENED';
	public const FIELD_LAST_OPENED_AT = 'USER_LAST_OPENED.OPENED_AT';

	/** @var array<string, string> */
	private array $sort = [];

	/**
	 * @param array<string, string|SortDirection> $raw
	 */
	public function __construct(array $raw = [])
	{
		foreach ($raw as $field => $direction)
		{
			$dir = $this->normalizeDirection($direction);

			match ($field) {
				self::FIELD_ID => $this->byId($dir),
				self::FIELD_TITLE => $this->byTitle($dir),
				self::FIELD_CREATED_AT => $this->byCreatedAt($dir),
				self::FIELD_UPDATED_AT => $this->byUpdatedAt($dir),
				self::FIELD_IS_PINNED => $this->byPinned($dir),
				self::FIELD_PINNED_AT => $this->byPinnedAt($dir),
				self::FIELD_IS_OWNED_BY_USER => $this->byOwnedByUser($dir),
				self::FIELD_IS_LAST_OPENED => $this->byLastOpened($dir),
				self::FIELD_LAST_OPENED_AT => $this->byLastOpenedAt($dir),
				default => null,
			};
		}
	}

	public function byId(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_ID, $direction);
	}

	public function byTitle(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_TITLE, $direction);
	}

	public function byCreatedAt(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_CREATED_AT, $direction);
	}

	public function byUpdatedAt(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_UPDATED_AT, $direction);
	}

	/**
	 * Sort by user-pin flag. Requires CatalogItemFilter::userContext().
	 */
	public function byPinned(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_IS_PINNED, $direction);
	}

	/**
	 * Sort by user-pin timestamp. Requires CatalogItemFilter::userContext().
	 */
	public function byPinnedAt(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_PINNED_AT, $direction);
	}

	/**
	 * Sort by ownership flag. Requires CatalogItemFilter::userContext().
	 */
	public function byOwnedByUser(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_IS_OWNED_BY_USER, $direction);
	}

	/**
	 * Sort by last-opened presence flag (1 = opened at least once, 0 = never). Requires CatalogItemFilter::userContext().
	 */
	public function byLastOpened(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_IS_LAST_OPENED, $direction);
	}

	/**
	 * Sort by last-opened timestamp. Requires CatalogItemFilter::userContext().
	 */
	public function byLastOpenedAt(SortDirection $direction = SortDirection::Desc): self
	{
		return $this->add(self::FIELD_LAST_OPENED_AT, $direction);
	}

	public function isEmpty(): bool
	{
		return $this->sort === [];
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return $this->sort;
	}

	private function add(string $field, SortDirection $direction): self
	{
		$this->sort[$field] = $direction->value;

		return $this;
	}

	private function normalizeDirection(string|SortDirection $direction): SortDirection
	{
		if ($direction instanceof SortDirection)
		{
			return $direction;
		}

		return strtoupper($direction) === SortDirection::Desc->value
			? SortDirection::Desc
			: SortDirection::Asc
		;
	}
}
