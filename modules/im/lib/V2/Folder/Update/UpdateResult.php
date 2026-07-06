<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Update;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Result;

/**
 * Result of {@see \Bitrix\Im\V2\Folder\FolderService::update}.
 *
 * @extends Result<Folder>
 */
class UpdateResult extends Result
{
	public function setFolder(Folder $folder): self
	{
		$this->setResult($folder);

		return $this;
	}

	public function getFolder(): ?Folder
	{
		return $this->getResult();
	}
}
