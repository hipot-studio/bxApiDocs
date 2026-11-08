<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Application;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;

class AddObserverService
{
	private const LOCK_PREFIX = 'tasks_v2_add_observer_';
	private const LOCK_TIMEOUT = 5;

	public function __construct(
		private readonly TaskRightService $taskRightService,
		private readonly TaskMemberRepositoryInterface $taskMemberRepository,
		private readonly AccessRequestService $accessRequestService,
		private readonly MemberService $memberService,
	)
	{

	}

	public function addObserver(int $taskId, int $currentUserId, int $candidateId): AddObserverOutcome
	{
		if (!User::isSuper($currentUserId) && !$this->taskRightService->canView($currentUserId, $taskId))
		{
			return AddObserverOutcome::NoPermission;
		}

		if (!$this->canAssignAuditor($taskId, $currentUserId, $candidateId))
		{
			return AddObserverOutcome::NotAllowedByPolicy;
		}

		if (!User::isActive($candidateId))
		{
			return AddObserverOutcome::UserInactive;
		}

		return $this->addObserverExclusively($taskId, $currentUserId, $candidateId);
	}

	private function addObserverExclusively(int $taskId, int $currentUserId, int $candidateId): AddObserverOutcome
	{
		$connection = Application::getConnection();
		$lockName = self::LOCK_PREFIX . $taskId;

		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			throw new \RuntimeException('Unable to acquire the add-observer lock for task ' . $taskId);
		}

		try
		{
			if ($this->isAuditor($taskId, $candidateId))
			{
				$this->accessRequestService->clearAccessRequest($candidateId, $taskId);

				return AddObserverOutcome::AlreadyObserver;
			}

			if (!$this->accessRequestService->hasAccessRequest($candidateId, $taskId))
			{
				return AddObserverOutcome::NoRequest;
			}

			$this->memberService->addAuditors(
				$taskId,
				[$candidateId],
				new UpdateConfig(
					userId: $currentUserId,
					useConsistency: true,
				),
			);

			if ($this->isAuditor($taskId, $candidateId))
			{
				$this->accessRequestService->clearAccessRequest($candidateId, $taskId);
			}

			return AddObserverOutcome::Success;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function canAssignAuditor(int $taskId, int $currentUserId, int $candidateId): bool
	{
		$rule = ActionDictionary::TASK_ACTIONS['addAuditors'];

		$rights = $this->taskRightService->get(
			[$rule => $rule],
			$taskId,
			$currentUserId,
			[$rule => [$candidateId]],
		);

		return $rights[$rule] ?? false;
	}

	private function isAuditor(int $taskId, int $candidateId): bool
	{
		$membership = $this->taskMemberRepository->getMembershipForUserIdAndTaskIds($candidateId, [$taskId]);

		return in_array(MemberTable::MEMBER_TYPE_AUDITOR, $membership[$taskId] ?? [], true);
	}
}
