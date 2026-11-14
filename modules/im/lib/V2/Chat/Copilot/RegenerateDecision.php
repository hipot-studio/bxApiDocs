<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

/**
 * Verdict of the aiassistant subscriber on a single repeat attempt. Accepted means the
 * generation started, ignored means a product refusal the user is not told about, error
 * means the attempt failed and the reason may say who notifies the user.
 */
enum RegenerateDecision: string
{
	case Accepted = 'accepted';
	case Ignored = 'ignored';
	case Error = 'error';
}
