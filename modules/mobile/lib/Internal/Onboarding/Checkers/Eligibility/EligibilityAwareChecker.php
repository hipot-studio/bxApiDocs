<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility;

/**
 * A check of a step that can be closed by the permission instead of by the deed.
 *
 * isCompleted() answers one thing - "the series has nothing left to ask of this user here" -
 * and says nothing about which of the two closed it, because the pipeline has no use for the
 * difference: either way the path moves on. The dev stand has. A step shown as done for a user
 * who never did it reads as a broken check, and the pages send pushes by what they show, so
 * they ask the reason here and say "пропущена" where the pipeline says "закрыта".
 *
 * Declared by the check rather than looked up by step id, so an eligibility added to another
 * step appears on the stand by itself.
 */
interface EligibilityAwareChecker
{
	/**
	 * Whether the portal lets this user perform the action of the step at all.
	 */
	public function isUserEligible(int $userId): bool;
}
