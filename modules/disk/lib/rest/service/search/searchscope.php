<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

final class SearchScope
{
	public function __construct(
		private readonly ?int $storageId,
		private readonly ?int $folderId,
		private readonly SearchType $type,
	)
	{
	}

	public function getStorageId(): ?int
	{
		return $this->storageId;
	}

	public function getFolderId(): ?int
	{
		return $this->folderId;
	}

	public function getType(): SearchType
	{
		return $this->type;
	}
}
