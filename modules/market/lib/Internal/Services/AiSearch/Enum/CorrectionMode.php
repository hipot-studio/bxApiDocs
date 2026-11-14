<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch\Enum;

enum CorrectionMode: string
{
	case Auto = 'auto';
	case Original = 'original';
	case Off = 'off';
}
