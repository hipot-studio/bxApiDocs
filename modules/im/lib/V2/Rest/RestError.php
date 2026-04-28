<?php

namespace Bitrix\Im\V2\Rest;

use Bitrix\Im\V2\Error;
use Bitrix\Main\Localization\Loc;

class RestError extends Error
{
	public const ACCESS_ERROR = 'ACCESS_ERROR';
	public const WRONG_DATETIME_FORMAT = 'WRONG_DATETIME_FORMAT';

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_REST_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_REST_{$code}_DESC", $replacements) ?: '';
	}
}