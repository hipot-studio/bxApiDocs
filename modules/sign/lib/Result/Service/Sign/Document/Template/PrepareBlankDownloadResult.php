<?php

namespace Bitrix\Sign\Result\Service\Sign\Document\Template;

use Bitrix\Sign\Result\SuccessResult;

class PrepareBlankDownloadResult extends SuccessResult
{
	public function __construct(
		public readonly int $templatesCount,
		public readonly int $skippedCount,
	)
	{
	}
}
