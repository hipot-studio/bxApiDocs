<?php

namespace Bitrix\Im\V2\Message\Scheduled\Error;

use Bitrix\Main\Localization\Loc;
use Bitrix\Im\V2\Error;

class ScheduledMessageError extends Error
{
	public const SEND_AT_OUT_OF_RANGE = 'SCHEDULED_MESSAGE_SEND_AT_OUT_OF_RANGE';
	public const ACCESS_DENIED = 'SCHEDULED_MESSAGE_ACCESS_DENIED';
	public const CHAT_NOT_FOUND = 'SCHEDULED_MESSAGE_CHAT_NOT_FOUND';
	public const PER_CHAT_LIMIT_EXCEEDED = 'SCHEDULED_MESSAGE_PER_CHAT_LIMIT_EXCEEDED';
	public const NOT_FOUND = 'SCHEDULED_MESSAGE_NOT_FOUND';
	public const INVALID_SEND_AT = 'SCHEDULED_MESSAGE_INVALID_SEND_AT';
	public const INVALID_UUID = 'SCHEDULED_MESSAGE_INVALID_UUID';
	public const NOT_AVAILABLE = 'SCHEDULED_MESSAGE_NOT_AVAILABLE';

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_{$code}_DESC", $replacements) ?: '';
	}
}
