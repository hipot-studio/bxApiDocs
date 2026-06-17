<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMemberMapper;

class TaskMemberRepository implements TaskMemberRepositoryInterface
{
	protected array $membershipCache = [];

	public function __construct(
		private readonly TaskMemberMapper $memberMapper,
	)
	{

	}

	public function get(int $taskId): Entity\UserCollection
	{
		$members = MemberTable::query()
			->setSelect(['USER_ID', 'TYPE'])
			->where('TASK_ID', $taskId)
			->exec()
			->fetchAll();

		return $this->memberMapper->mapToCollection($members);
	}

	public function getByTaskIds(array $taskIds): Entity\TaskMemberCollection
	{
		if (empty($taskIds))
		{
			return new Entity\TaskMemberCollection();
		}

		$members = MemberTable::query()
			->setSelect(['USER_ID', 'TYPE', 'TASK_ID'])
			->whereIn('TASK_ID', $taskIds)
			->exec()
			->fetchAll();

		return $this->memberMapper->mapToTaskMemberCollection($members);
	}

	public function getCreator(int $taskId): ?Entity\User
	{
		return $this->getMemberByType($taskId, MemberTable::MEMBER_TYPE_ORIGINATOR);
	}

	public function getResponsible(int $taskId): Entity\User
	{
		return $this->getMemberByType($taskId, MemberTable::MEMBER_TYPE_RESPONSIBLE);
	}

	public function getAccomplices(int $taskId): Entity\UserCollection
	{
		return $this->getMembersByType($taskId, MemberTable::MEMBER_TYPE_ACCOMPLICE);
	}

	public function getAuditors(int $taskId): Entity\UserCollection
	{
		return $this->getMembersByType($taskId, MemberTable::MEMBER_TYPE_AUDITOR);
	}

	private function getMemberByType(int $taskId, string $type): ?Entity\User
	{
		$member = MemberTable::query()
			->setSelect(['USER_ID', 'TYPE'])
			->where('TASK_ID', $taskId)
			->where('TYPE', $type)
			->fetch();

		if (!is_array($member))
		{
			return null;
		}

		return $this->memberMapper->mapToEntity($member);
	}

	private function getMembersByType(int $taskId, string $type): Entity\UserCollection
	{
		$members = MemberTable::query()
			->setSelect(['USER_ID', 'TYPE'])
			->where('TASK_ID', $taskId)
			->where('TYPE', $type)
			->fetchAll();

		return $this->memberMapper->mapToCollection($members);
	}

	public function getMembershipForUserIdAndTaskIds(int $userId, array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$taskIdsToSearch = array_diff($taskIds, array_keys($this->membershipCache[$userId] ?? []));

		if (!empty($taskIdsToSearch))
		{
			$recordset = MemberTable::query()
				->setSelect(['TASK_ID', 'TYPE'])
				->where('USER_ID', $userId)
				->whereIn('TASK_ID', $taskIdsToSearch)
				->exec();

			while($data = $recordset->fetch())
			{
				$this->membershipCache[$userId][(int)$data['TASK_ID']][] = $data['TYPE'];
			}
		}

		if (empty($this->membershipCache[$userId]))
		{
			return [];
		}

		return $this->membershipCache[$userId];
	}

	public function saveMulti(int $taskId, UserCollection $userCollection): void
	{
		if ($taskId < 1)
		{
			return;
		}

		$insertRows = [];
		$invalidateCacheUsers = [];

		foreach ($userCollection as $user)
		{
			$userId = (int)$user->id;
			$invalidateCacheUsers[] = $userId;
			$insertRows[] = [
				'TASK_ID' => $taskId,
				'USER_ID' => $userId,
				'TYPE' => (string)$user->role,
			];
		}

		if (empty($insertRows))
		{
			return;
		}

		$result = MemberTable::addInsertIgnoreMulti($insertRows);
		if (!$result->isSuccess())
		{
			throw new SqlQueryException($result->getError()?->getMessage() ?? 'Failed to save task members');
		}

		$this->invalidateCache($taskId, $invalidateCacheUsers);
	}

	public function deleteAllInTask(int $taskId): void
	{
		if ($taskId < 1)
		{
			return;
		}

		MemberTable::deleteList(
			[
				'=TASK_ID' => $taskId,
			]
		);

		$this->invalidateCache($taskId);
	}

	protected function invalidateCache(int $taskId, ?array $userIds = null): void
	{
		$userIds = $userIds ?? array_keys($this->membershipCache);

		foreach ($userIds as $userId)
		{
			unset($this->membershipCache[$userId][$taskId]);

			if (empty($this->membershipCache[$userId]))
			{
				unset($this->membershipCache[$userId]);
			}
		}
	}
}
