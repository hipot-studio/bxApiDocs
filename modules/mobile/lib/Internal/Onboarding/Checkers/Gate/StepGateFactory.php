<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

/**
 * Maps a gate condition of the campaign document onto the gate implementing it. The
 * single place where a new condition is registered.
 *
 * A step whose document node declares no usable condition gets DEFAULT_CONDITION: the
 * gate of the message step is a product requirement, so an incomplete document must not
 * silently drop it.
 */
final class StepGateFactory
{
	public const DEFAULT_CONDITION = GateCondition::COWORKER_JOINED_BY_INVITE_OR_AFTER_PUSH;

	public static function create(?GateCondition $condition): StepGate
	{
		return match ($condition ?? self::DEFAULT_CONDITION)
		{
			GateCondition::NONE => new AlwaysOpenGate(),
			GateCondition::COWORKER_JOINED => new CoworkerJoinedGate(),
			GateCondition::COWORKER_JOINED_BY_INVITE_OR_AFTER_PUSH => new InvitedOrJoinedAfterPushGate(),
		};
	}
}
