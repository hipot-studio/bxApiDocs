<?php

namespace Bitrix\UI\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Loader;

class RichTextUserFieldFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}

	public function getRequirements(): array
	{
		return [
			static fn (): bool => Loader::includeModule('ui'),
		];
	}
}
