<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

final class PinScopeState
{
	/**
	 * @param array<int, array<int, int>> $pinSortMapByFolder folderId → (chatId → PIN_SORT)
	 */
	public function __construct(
		private readonly array $pinSortMapByFolder = [],
	)
	{
	}

	/**
	 * @return int[] pinned chat ids in this folder (empty if none), insertion order preserved
	 */
	public function getPinnedChatIds(int $folderId): array
	{
		return array_keys($this->pinSortMapByFolder[$folderId] ?? []);
	}

	/**
	 * @return array<int, int> chatId → PIN_SORT
	 */
	public function getPinSortMap(int $folderId): array
	{
		return $this->pinSortMapByFolder[$folderId] ?? [];
	}

	/**
	 * Serialises to a plain array for {@see PinCache} storage.
	 *
	 * @internal Used only by PinCache.
	 * @return array{pinSortMapByFolder: array<int, array<int, int>>}
	 */
	public function toCacheArray(): array
	{
		return [
			'pinSortMapByFolder' => $this->pinSortMapByFolder,
		];
	}

	/**
	 * Restores an instance from the plain array stored in {@see PinCache}.
	 *
	 * @internal Used only by PinCache.
	 * @param array{pinSortMapByFolder?: mixed} $data
	 */
	public static function fromCacheArray(array $data): self
	{
		$pinSortMapByFolder = [];
		if (isset($data['pinSortMapByFolder']) && is_array($data['pinSortMapByFolder']))
		{
			foreach ($data['pinSortMapByFolder'] as $folderId => $sortMap)
			{
				if (is_array($sortMap))
				{
					$intKeyed = [];
					foreach ($sortMap as $chatId => $sort)
					{
						$intKeyed[(int)$chatId] = (int)$sort;
					}
					$pinSortMapByFolder[(int)$folderId] = $intKeyed;
				}
			}
		}

		return new self($pinSortMapByFolder);
	}
}
