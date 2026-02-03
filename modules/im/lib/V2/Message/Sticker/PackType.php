<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

enum PackType: string
{
	case Vendor = 'vendor';
	case Custom = 'custom';
}
