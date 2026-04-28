<?php

namespace Bitrix\Crm\Import\Contract\Enum;

use BackedEnum;

interface HasHintInterface extends BackedEnum
{
	public function getHint(): ?string;
}
