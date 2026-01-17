<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Error;
use Bitrix\Main\Localization\Loc;

class StickerError extends Error
{
	public const STICKERS_NOT_AVAILABLE = 'STICKERS_NOT_AVAILABLE';
	public const PACK_NOT_AVAILABLE = 'PACK_NOT_AVAILABLE';
	public const STICKER_SENDING_ERROR = 'STICKER_SENDING_ERROR';

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_STICKER_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_STICKER_{$code}_DESC", $replacements) ?: '';
	}
}
