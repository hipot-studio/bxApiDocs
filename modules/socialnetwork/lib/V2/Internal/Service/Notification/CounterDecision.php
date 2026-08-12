<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Notification;

/**
 * Decision returned by CounterPolicyResolver.
 *
 * Passthrough — the feature does not intervene; the caller applies its default behaviour.
 * CounterOn   — send normally with counter and push.
 * CounterOff  — suppress counter; keep message in recent (SILENT_WITH_RECENT for member,
 *               skipCounter + disallowPush for feed).
 */
enum CounterDecision
{
	case Passthrough;
	case CounterOn;
	case CounterOff;

	public function isPassthrough(): bool
	{
		return $this === self::Passthrough;
	}
}
