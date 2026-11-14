<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility;

use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Main\Loader;

/**
 * The right to invite an employee, asked of the platform per user.
 *
 * The same rule the menu of the application shows the invite button by: `Invitation`
 * answers it for the current user through PermissionInvitation, and the pass of the
 * campaign walks other people, so the permission object is built by user id - the way
 * intranet itself builds it for a user who is not the current one.
 */
final class InvitePermissionEligibility implements StepEligibility
{
	/** @var array<int, bool> answered once per user, the pass asks about each of them once */
	private array $answers = [];

	public function isEligible(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->answers[$userId] ??= $this->readPermission($userId);
	}

	/**
	 * A portal that cannot answer keeps the step as it was: the permission lives in two
	 * modules of somebody else, and a step silently closed for everyone would take the
	 * invite push out of the campaign without a word. So an unreadable permission means
	 * "the campaign goes on as before", and the reason is written down.
	 */
	private function readPermission(int $userId): bool
	{
		if (!Loader::includeModule('intranet') || !Loader::includeModule('humanresources'))
		{
			return true;
		}

		try
		{
			return (new PermissionInvitation($userId))->canInvite();
		}
		catch (\Throwable $exception)
		{
			\AddMessage2Log(
				'Onboarding invite step: permission unreadable for user ' . $userId . ': ' . $exception->getMessage(),
				'mobile',
			);

			return true;
		}
	}
}
