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
	public const MAX_STICKERS_ERROR = 'MAX_STICKERS_ERROR';
	public const MAX_PACKS_ERROR = 'MAX_PACKS_ERROR';
	public const ACCESS_DENIED = 'ACCESS_DENIED';
	public const WRONG_PACK_TYPE = 'WRONG_PACK_TYPE';
	public const PACK_CREATION_ERROR = 'PACK_CREATION_ERROR';
	public const LINK_EXISTS = 'LINK_EXISTS';
	public const PACK_NOT_FOUND = 'PACK_NOT_FOUND';
	public const EMPTY_PACK_NAME = 'EMPTY_PACK_NAME';
	public const EMPTY_STICKERS = 'EMPTY_STICKERS';

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_STICKER_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_STICKER_{$code}_DESC", $replacements) ?: '';
	}
}
