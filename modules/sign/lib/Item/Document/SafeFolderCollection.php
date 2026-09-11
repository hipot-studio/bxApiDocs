<?php

namespace Bitrix\Sign\Item\Document;

use Bitrix\Sign\Item\Collection;

/**
 * @extends Collection<SafeFolder>
 */
class SafeFolderCollection extends Collection
{
	/**
	 * @return list<int>
	 */
	public function getIds(): array
	{
		return array_map(
			static fn(SafeFolder $folder): int => $folder->getId(),
			$this->toArray(),
		);
	}

	public function findById(int $id): ?SafeFolder
	{
		return $this->findByRule(static fn(SafeFolder $folder): bool => $folder->id === $id);
	}

	protected function getItemClassName(): string
	{
		return SafeFolder::class;
	}
}
