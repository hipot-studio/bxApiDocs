<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Action;

use Bitrix\Im\V2\Error;

class ActionError extends Error
{
	public const BLOCK_NOT_FOUND = 'BLOCK_NOT_FOUND';
	public const ELEMENT_NOT_FOUND = 'ELEMENT_NOT_FOUND';
	public const ELEMENT_NOT_ALLOWED = 'ELEMENT_NOT_ALLOWED';
	public const FORM_DISABLED = 'FORM_DISABLED';
}
