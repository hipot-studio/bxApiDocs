<?php

declare(strict_types=1);

namespace Bitrix\Disk\Search;

use Bitrix\Disk\Internals\ObjectTable;

final class StorageFileFinderOptions
{
	private int $limit;
	private int $offset;

	public function __construct(
		int $limit,
		int $offset = 0,
		private readonly array $objectTypes = [ObjectTable::TYPE_FILE],
		private readonly ?int $storageId = null,
		private readonly ?int $folderId = null,
		private readonly ?array $proxyTypes = null,
		private readonly ?string $folderExcludedProxyType = null,
	)
	{
		$this->limit = max(1, $limit);
		$this->offset = max(0, $offset);
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function getObjectTypes(): array
	{
		return $this->objectTypes;
	}

	public function getStorageId(): ?int
	{
		return $this->storageId;
	}

	public function getFolderId(): ?int
	{
		return $this->folderId;
	}

	public function getProxyTypes(): ?array
	{
		return $this->proxyTypes;
	}

	public function getFolderExcludedProxyType(): ?string
	{
		return $this->folderExcludedProxyType;
	}
}
