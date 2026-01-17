<?php

namespace Bitrix\Main\Cli\Helper;

use Bitrix\Main\Result;

class GenerateResult extends Result
{
	public function __construct(
		public readonly ?string $path = null,
	)
	{
		parent::__construct();
	}

	public function getSuccessMessage(): string
	{
		return "\n<info>Generated file '{$this->path}'</info>\n";
	}
}
