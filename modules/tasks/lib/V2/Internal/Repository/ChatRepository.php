<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Model\TaskChatTable;
use Bitrix\Tasks\V2\Internal\Integration\Im;

class ChatRepository implements ChatRepositoryInterface
{
	public function getByTaskId(int $taskId): ?Im\Entity\Chat
	{
		$data =
			TaskChatTable::query()
				->setSelect(['CHAT_ID'])
				->where('TASK_ID', $taskId)
				->fetch()
		;

		$chatId = (int)($data['CHAT_ID'] ?? 0);

		if ($chatId <= 0)
		{
			return null;
		}

		return new Im\Entity\Chat(
			id: $chatId,
			entityId: $taskId,
			entityType: Im\Chat::ENTITY_TYPE,
		);
	}

	public function findChatIdsByTaskIds(array $taskIds): array
	{
		$data = TaskChatTable::query()
			->setSelect(['TASK_ID', 'CHAT_ID'])
			->whereIn('TASK_ID', array_map('intval', $taskIds))
			->fetchAll();

		$map = array_column($data, 'CHAT_ID', 'TASK_ID');
		return array_map('intval', $map);
	}

	public function findChatIdsByUserIdAndGroupIds(int $userId, array $groupIds): array
	{
		$data = TaskChatTable::query()
			->setSelect(['CHAT_ID'])
			->registerRuntimeField(
				'TASK',
				(new Reference('TASK', TaskTable::class, ['=this.TASK_ID' => 'ref.ID']))->configureJoinType('inner')
			)
			->registerRuntimeField(
				'MEMBER',
				(new Reference('MEMBER', MemberTable::class, ['=this.TASK_ID' => 'ref.TASK_ID']))->configureJoinType('inner')
			)
			->where('MEMBER.USER_ID', $userId)
			->whereIn('TASK.GROUP_ID', $groupIds)
			->fetchAll();

		$data = array_column($data, 'CHAT_ID');
		Collection::normalizeArrayValuesByInt($data, false);

		return $data;
	}

	public function save(int $chatId, int $taskId): void
	{
		TaskChatTable::add([
			'TASK_ID' => $taskId,
			'CHAT_ID' => $chatId,
		]);
	}

	public function delete(int $taskId): void
	{
		TaskChatTable::deleteByFilter([
			'TASK_ID' => $taskId,
		]);
	}
}
