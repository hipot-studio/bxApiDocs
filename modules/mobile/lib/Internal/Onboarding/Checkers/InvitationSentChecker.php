<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Intranet\Internals\InvitationTable;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility\EligibilityAwareChecker;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility\InvitePermissionEligibility;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility\StepEligibility;

class InvitationSentChecker extends BulkRecordExistsStepChecker implements EligibilityAwareChecker
{
	/** Left unset until somebody asks, see getEligibility(). */
	private ?StepEligibility $eligibility = null;

	public function __construct(?StepEligibility $eligibility = null)
	{
		$this->eligibility = $eligibility;
	}

	/**
	 * The step is closed either by the deed or by the user not being allowed to do it.
	 *
	 * The permission is asked second and only of those who have not invited anyone: the deed
	 * is already known for the whole selection from one query, while the permission costs a
	 * structure access check per user. And a user who invited someone and later lost the right
	 * has done what the series asked - the answer of the step does not depend on the permission
	 * he holds today.
	 */
	public function isCompleted(int $userId, DateTime $sinceDate): bool
	{
		if (parent::isCompleted($userId, $sinceDate))
		{
			return true;
		}

		return $userId > 0 && !$this->getEligibility()->isEligible($userId);
	}

	/**
	 * Memoised by the eligibility itself, so the stand asking after the pipeline costs nothing.
	 */
	public function isUserEligible(int $userId): bool
	{
		return $userId > 0 && $this->getEligibility()->isEligible($userId);
	}

	/**
	 * Built on the first question rather than in the constructor: the pass creates the registry
	 * for every region of the campaign and most passes never reach this step, and a subclass
	 * that replaces the constructor still gets the rule of the platform.
	 */
	protected function getEligibility(): StepEligibility
	{
		return $this->eligibility ??= new InvitePermissionEligibility();
	}

	protected function getModuleId(): string
	{
		return 'intranet';
	}

	protected function getAuthorField(): string
	{
		return 'ORIGINATOR_ID';
	}

	protected function recordExistsForUser(int $userId, DateTime $sinceDate): bool
	{
		$row = InvitationTable::query()
			->setSelect(['ID'])
			->where('ORIGINATOR_ID', $userId)
			->where('DATE_CREATE', '>=', $sinceDate)
			->setLimit(1)
			->fetch()
		;

		return !empty($row);
	}

	/**
	 * One query for the whole pass instead of one per employee. `b_intranet_invitation` has no
	 * index starting with ORIGINATOR_ID, so the per-user route re-reads the same date range of
	 * the campaign for every candidate; grouped, that range is read once.
	 *
	 * @param int[] $userIds
	 */
	protected function createBatchQuery(array $userIds, DateTime $sinceDate): Query
	{
		return InvitationTable::query()
			->setSelect(['ORIGINATOR_ID'])
			->whereIn('ORIGINATOR_ID', $userIds)
			->where('DATE_CREATE', '>=', $sinceDate)
			->setGroup(['ORIGINATOR_ID'])
		;
	}
}
