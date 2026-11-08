<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Vibecode;

use Bitrix\Main\Localization\Loc;

enum VibecodeBrand: string
{
	case Bitrix24 = 'bitrix24';
	case Alaio = 'alaio';

	public function getDisplayName(): string
	{
		$messageCode = match ($this)
		{
			self::Bitrix24 => 'VIBECODECONNECTOR_BRAND_BITRIX24',
			self::Alaio => 'VIBECODECONNECTOR_BRAND_ALAIO',
		};

		return Loc::getMessage($messageCode) ?? '';
	}
}
