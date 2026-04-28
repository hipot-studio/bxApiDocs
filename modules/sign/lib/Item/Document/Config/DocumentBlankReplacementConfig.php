<?php

namespace Bitrix\Sign\Item\Document\Config;

use Bitrix\Sign\Contract;

final class DocumentBlankReplacementConfig implements Contract\Item
{
	public function __construct(
		public int $blankReplacementFileId,
		public bool $copyFileForBlank = true,
		public bool $copyBlocksOnFileReplace = false,
	)
	{
	}
}
