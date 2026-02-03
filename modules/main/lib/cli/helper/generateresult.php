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
		return "\nA file has been created:\n<info>{$this->path}</info>\n";
	}
}
