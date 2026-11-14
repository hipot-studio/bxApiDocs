<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

/**
 * Gate of a step that has no extra condition. Lets a step declared with `"gate": "none"`
 * be credited by its own check alone, without the checker knowing that a gate exists.
 */
class AlwaysOpenGate implements StepGate
{
	public function isOpen(int $userId, GateContext $context): bool
	{
		return $userId > 0;
	}
}
