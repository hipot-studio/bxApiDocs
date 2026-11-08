<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Error;

class FormSubmitError extends Error
{
	public const FORM_DISABLED = 'FORM_DISABLED';
	public const UNKNOWN_FIELD_ID = 'UNKNOWN_FIELD_ID';
	public const FIELD_DISABLED = 'FIELD_DISABLED';
	public const INVALID_VALUE_TYPE = 'INVALID_VALUE_TYPE';
	public const UNKNOWN_OPTION_ID = 'UNKNOWN_OPTION_ID';
	public const VALUES_LENGTH_EXCEEDED = 'VALUES_LENGTH_EXCEEDED';
}
