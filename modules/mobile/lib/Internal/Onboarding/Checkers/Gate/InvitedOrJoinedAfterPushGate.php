<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalEmployeeQuery;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * Reading of the message gate narrowed to the colleague the series actually asked for. The gate
 * opens when a joined employee of the portal satisfies either half:
 *
 * - they registered after the invite push was sent - a colleague who appeared in answer to
 *   the nudge, no matter whether they took the invitation link or signed up on their own;
 * - our user invited them (ORIGINATOR_ID) - the invitation record binds the two sides even
 *   when the invitee record itself is older, as happens when an existing email user is
 *   transferred to an employee on invite.
 *
 * Either half alone leaves a hole, hence both: the registration date misses the
 * transferred email user, and the invitation record misses whoever registered themselves
 * instead of following the invitation.
 *
 * Employees who were already on the portal before the nudge open neither half, which is
 * the point of this condition over CoworkerJoinedGate.
 */
class InvitedOrJoinedAfterPushGate extends JoinedEmployeeGate implements BulkStepGate
{
	/**
	 * Upper bound of one read of the announced selection. Deliberately not inherited from the
	 * bound of BulkRecordExistsStepChecker: there a chunk is a list of ids inside one `IN`,
	 * here it is a disjunction carrying a condition pair per candidate, so the same number
	 * buys a far larger statement - 500 candidates compile to some 63 KB of SQL against the
	 * couple of KB a list of 500 ids would be.
	 *
	 * Size is still not what bounds it: `max_allowed_packet` of the stand is counted in
	 * megabytes. What bounds it is the plan - the disjunction leads with a column no index of
	 * `b_intranet_invitation` starts with, so it is read as one pass over the date range of the
	 * campaign, which is exactly what asking once instead of per candidate is for. That has
	 * only been measured where the invitation table is small; a portal that invites at scale is
	 * where this number should be revisited.
	 */
	private const CHUNK_SIZE = 500;

	/** @var array<int, GateContext> */
	private array $contexts = [];

	/** @var array<int, bool>|null */
	private ?array $openUsers = null;

	public function preload(array $contexts): void
	{
		$this->contexts = [];
		foreach ($contexts as $userId => $context)
		{
			if ($userId > 0)
			{
				$this->contexts[$userId] = new GateContext($context->getThreshold());
			}
		}
		$this->openUsers = null;
	}

	/** @return array<int, bool> */
	private function readOpenUsers(): array
	{
		$threshold = min(array_map(
			static fn (GateContext $context): int => $context->getThreshold()->getTimestamp(),
			$this->contexts,
		));
		$employees = $this->fetchLatestJoinedEmployees(DateTime::createFromTimestamp($threshold));
		$open = array_fill_keys(array_keys($this->contexts), false);
		$pending = [];
		foreach ($this->contexts as $userId => $context)
		{
			foreach ($employees as $employee)
			{
				if (
					(int)$employee['ID'] !== $userId
					&& $employee['DATE_REGISTER']->getTimestamp() > $context->getThreshold()->getTimestamp()
				)
				{
					$open[$userId] = true;

					break;
				}
			}
			if (!$open[$userId])
			{
				$pending[$userId] = $context;
			}
		}

		foreach (array_chunk($pending, self::CHUNK_SIZE, true) as $chunk)
		{
			foreach ($this->fetchInvitedJoinedUsers($chunk) as $userId)
			{
				$open[(int)$userId] = true;
			}
		}

		return $open;
	}

	protected function fetchLatestJoinedEmployees(DateTime $threshold): array
	{
		// Two latest employees suffice even when the latest one is the candidate himself.
		return PortalEmployeeQuery::create()
			->setSelect(['ID', 'DATE_REGISTER'])
			->addFilter('CONFIRM_CODE', false)
			->where('DATE_REGISTER', '>', $threshold)
			->setOrder(['DATE_REGISTER' => 'DESC', 'ID' => 'DESC'])
			->setLimit(2)
			->fetchAll()
		;
	}

	/** @param array<int, GateContext> $contexts */
	protected function createBatchInvitedJoinedQuery(array $contexts): Query
	{
		$invitations = Query::filter()->logic('or');
		foreach ($contexts as $userId => $context)
		{
			$invitations->where(Query::filter()
				->where('INVITATION.ORIGINATOR_ID', $userId)
				->where('INVITATION.DATE_CREATE', '>', $context->getThreshold()),
			);
		}

		return PortalEmployeeQuery::create()
			->setSelect(['ORIGINATOR_ID' => 'INVITATION.ORIGINATOR_ID'])
			->addFilter('CONFIRM_CODE', false)
			->whereColumn('ID', '!=', 'INVITATION.ORIGINATOR_ID')
			->where($invitations)
			->setDistinct()
		;
	}

	/**
	 * @param array<int, GateContext> $contexts
	 * @return int[]
	 */
	protected function fetchInvitedJoinedUsers(array $contexts): array
	{
		return array_column($this->createBatchInvitedJoinedQuery($contexts)->fetchAll(), 'ORIGINATOR_ID');
	}

	protected function hasJoinedColleague(int $userId, GateContext $context): bool
	{
		if (
			isset($this->contexts[$userId])
			&& $this->contexts[$userId]->getThreshold()->getTimestamp() === $context->getThreshold()->getTimestamp()
		)
		{
			$this->openUsers ??= $this->readOpenUsers();

			return $this->openUsers[$userId];
		}

		return $this->exists($this->createRegisteredAfterQuery($userId, $context->getThreshold()))
			|| $this->exists($this->createBatchInvitedJoinedQuery([$userId => $context])->setLimit(1))
		;
	}

	protected function createRegisteredAfterQuery(int $userId, DateTime $threshold): Query
	{
		return $this->createJoinedEmployeeQuery($userId)->where('DATE_REGISTER', '>', $threshold);
	}
}
