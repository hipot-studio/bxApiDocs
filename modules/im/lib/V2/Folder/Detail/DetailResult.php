<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Detail;

use Bitrix\Im\V2\Result;

/**
 * @extends Result<FolderDetail>
 */
class DetailResult extends Result
{
	public function setDetail(FolderDetail $detail): self
	{
		$this->setResult($detail);

		return $this;
	}

	public function getDetail(): ?FolderDetail
	{
		return $this->getResult();
	}
}
