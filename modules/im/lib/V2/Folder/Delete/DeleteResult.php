<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Delete;

use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Folder\FolderService::delete}.
 *
 * @extends Result<int>
 */
class DeleteResult extends Result
{
	public function setFolderId(int $folderId): self
	{
		$this->setResult($folderId);

		return $this;
	}

	public function getFolderId(): ?int
	{
		return $this->getResult();
	}
}
