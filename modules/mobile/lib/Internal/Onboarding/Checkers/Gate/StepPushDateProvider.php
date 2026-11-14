<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * Source of the "when did we ask for this" date. Implemented by the campaign state
 * storage (OnboardingPushService); declared here so the checkers depend on the date
 * they need rather than on the storage.
 */
interface StepPushDateProvider
{
	/**
	 * Date the push of the given type was sent to the user for the first time, or null
	 * when it has never been sent. The first send rather than the last one: a date that
	 * moves with every retry would let a colleague count on one pass and stop counting
	 * on the next.
	 */
	public function getStepFirstSentAt(int $userId, PushType $pushType): ?DateTime;
}
