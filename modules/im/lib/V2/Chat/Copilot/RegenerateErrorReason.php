<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

/**
 * Machine-readable cause of a failed repeat attempt. The set is closed on purpose: the cause drives
 * a decision on the client, so an unknown value is no better than no value at all. A cause the user
 * should read about comes as a separate text, not as a new code here.
 */
enum RegenerateErrorReason: string
{
	case Limit = 'limit';
}
