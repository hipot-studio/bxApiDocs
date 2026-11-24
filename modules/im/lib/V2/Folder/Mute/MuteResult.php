<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Mute;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Result;

/**
 * Result of {@see MuteService::setMuted}: the folder carrying the rule as it is stored now (DTO-01).
 *
 * The folder is set on an idempotent call as well - the caller answers with the same object either
 * way, and a repeated call differs only in what it wrote, not in what it tells.
 *
 * @extends Result<Folder>
 */
class MuteResult extends Result
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
