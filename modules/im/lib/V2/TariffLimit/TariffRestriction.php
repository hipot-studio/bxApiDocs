<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\TariffLimit;

interface TariffRestriction extends \JsonSerializable
{
	public function getCode(): string;
}
