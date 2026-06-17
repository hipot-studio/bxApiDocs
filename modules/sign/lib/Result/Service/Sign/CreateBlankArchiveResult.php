<?php

namespace Bitrix\Sign\Result\Service\Sign;

use Bitrix\Sign\Result\SuccessResult;

class CreateBlankArchiveResult extends SuccessResult
{
	public function __construct(
		public readonly string $filePath,
		public readonly string $fileName,
	)
	{
	}
}
