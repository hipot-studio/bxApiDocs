<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility;

/**
 * Whether the action of a step is open to this user at all.
 *
 * This is not the gate of a step and not its check. A check answers "did the user do it",
 * a gate answers "may the deed be credited yet"; both keep the step open while the answer
 * is no, and the series goes on asking for the deed. Eligibility answers "may the user do
 * it in the first place" - and a no is final, so the step is passed over instead: a series
 * that stops at an action the portal does not let the user perform would push at him until
 * the attempts of the step run out.
 */
interface StepEligibility
{
	public function isEligible(int $userId): bool;
}
