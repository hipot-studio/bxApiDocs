<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Relation;

use Bitrix\Im\V2\Error;
use Bitrix\Main\Localization\Loc;

class RelationError extends Error
{
	public const NOT_FOUND = 'RELATION_NOT_FOUND_ERROR';

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_RELATION_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_RELATION_{$code}_DESC", $replacements) ?: '';
	}
}
