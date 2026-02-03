<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMemberMapper;

class TaskMemberRepository implements TaskMemberRepositoryInterface
{
	private array $membershipCache = [];

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
}
