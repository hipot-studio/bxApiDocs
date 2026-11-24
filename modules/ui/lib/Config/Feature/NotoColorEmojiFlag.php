<?php

namespace Bitrix\UI\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

class NotoColorEmojiFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
