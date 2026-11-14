<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

/**
 * Literal reading of the message gate: any employee of the portal, other than the user
 * himself, has joined. Blind to who invited him and to when he came - an employee who was on the
 * portal before the series started opens this gate too.
 */
class CoworkerJoinedGate extends JoinedEmployeeGate
{
	protected function hasJoinedColleague(int $userId, GateContext $context): bool
	{
		return $this->exists($this->createJoinedEmployeeQuery($userId));
	}
}
