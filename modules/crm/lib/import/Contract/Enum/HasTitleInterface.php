<?php

namespace Bitrix\Crm\Import\Contract\Enum;

use BackedEnum;

interface HasTitleInterface extends BackedEnum
{
	public function getTitle(): ?string;
}
