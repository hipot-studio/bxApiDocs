<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility;

use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * Whether a push of this type may be sent to this user at all.
 *
 * The step of a campaign path already asks its own eligibility, and for a portal running the
 * campaign document that is where the invite push stops. It is not the only schedule: every
 * region on the legacy day map sends by the day of the map, knows no steps and asks no checks,
 * so the same push would go out there to a user the portal does not let invite anybody. This is
 * asked once, right before the send, and therefore covers both schedules and anything that
 * resolves a push type in the future.
 *
 * Only the types whose action the portal closes to some of its employees are listed. A type
 * nobody gates costs no question - the map answers "allowed" without touching the platform.
 */
final class PushEligibility
{
	/** @var array<string, StepEligibility> push type => the permission its action needs */
	private array $rules;

	public function __construct(?StepEligibility $inviteEligibility = null)
	{
		$this->rules = [
			PushType::INVITE->value => $inviteEligibility ?? new InvitePermissionEligibility(),
		];
	}

	public function isAllowed(int $userId, PushType $pushType): bool
	{
		$eligibility = $this->rules[$pushType->value] ?? null;

		return $eligibility === null || $eligibility->isEligible($userId);
	}
}
