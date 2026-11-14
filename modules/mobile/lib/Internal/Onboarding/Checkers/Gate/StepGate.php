<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

/**
 * Extra condition a step check is subordinated to. A gate never tells whether the user
 * did the action of the step: it tells whether the action may be credited at all
 * (a message counts only once a colleague has joined the portal).
 *
 * The campaign document selects a gate by its GateCondition token, so changing the
 * condition of a step is a change of data. It cannot introduce one: every gate is
 * declared in code and registered in StepGateFactory.
 */
interface StepGate
{
	public function isOpen(int $userId, GateContext $context): bool;
}
