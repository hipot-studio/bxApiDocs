<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

use Bitrix\Im\V2\Error;
use Bitrix\Main\Localization\Loc;

final class ContactCardError extends Error
{
	public const INVALID = 'CONTACT_INVALID';
	public const NO_PHONE = 'CONTACT_NO_PHONE';
	public const CHAT_TYPE_RESTRICTED = 'CONTACT_CHAT_TYPE_RESTRICTED';
	public const FEATURE_DISABLED = 'CONTACT_FEATURE_DISABLED';
	public const PHOTO_NOT_IN_CHAT = 'CONTACT_PHOTO_NOT_IN_CHAT';

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_MESSAGE_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_MESSAGE_{$code}_DESC", $replacements) ?: '';
	}
}
