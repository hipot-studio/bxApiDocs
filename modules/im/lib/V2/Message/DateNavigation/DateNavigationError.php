<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\DateNavigation;

use Bitrix\Im\V2\Error;

class DateNavigationError extends Error
{
	public const NOT_AVAILABLE = 'DATE_NAVIGATION_NOT_AVAILABLE';
	public const WRONG_DATE_FORMAT = 'DATE_NAVIGATION_WRONG_DATE_FORMAT';
	public const WRONG_RANGE = 'DATE_NAVIGATION_WRONG_RANGE';
	public const MESSAGE_NOT_FOUND = 'DATE_NAVIGATION_MESSAGE_NOT_FOUND';
}
