<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Cache;

final class FolderListData
{
	/**
	 * @param array<int, array<string, mixed>> $folders     Flat rows from b_im_folder (id/type/code/title/sort/userId/parentId).
	 * @param array<int, int[]> $personalChatIds folderId → chatIds[]
	 */
	public function __construct(
		public readonly array $folders = [],
		public readonly array $personalChatIds = [],
	)
	{
	}

	/**
	 * Serialises to a plain array for Bitrix cache storage.
	 *
	 * @return array{folders: array<int, array<string, mixed>>, personalChatIds: array<int, int[]>}
	 */
	public function toArray(): array
	{
		return [
			'folders' => $this->folders,
			'personalChatIds' => $this->personalChatIds,
		];
	}

	/**
	 * Restores an instance from the plain array stored in cache.
	 *
	 * @param array{folders?: mixed, personalChatIds?: mixed} $data
	 */
	public static function fromArray(array $data): self
	{
		$folders = [];
		if (isset($data['folders']) && is_array($data['folders']))
		{
			foreach ($data['folders'] as $folderId => $row)
			{
				if (is_array($row))
				{
					$folders[(int)$folderId] = $row;
				}
			}
		}

		$personalChatIds = [];
		if (isset($data['personalChatIds']) && is_array($data['personalChatIds']))
		{
			foreach ($data['personalChatIds'] as $folderId => $chatIds)
			{
				if (is_array($chatIds))
				{
					$personalChatIds[(int)$folderId] = array_map('intval', $chatIds);
				}
			}
		}

		return new self($folders, $personalChatIds);
	}
}
