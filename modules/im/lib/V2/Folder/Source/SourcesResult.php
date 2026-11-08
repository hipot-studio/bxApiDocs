<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

use Bitrix\Im\V2\Result;

/**
 * Result of {@see FolderSourceService::getSources}.
 *
 * @extends Result<SourceList>
 */
class SourcesResult extends Result
{
	public function setSourceList(SourceList $sourceList): self
	{
		$this->setResult($sourceList);

		return $this;
	}

	public function getSourceList(): ?SourceList
	{
		return $this->getResult();
	}
}
