<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Im;

interface ChatRepositoryInterface
{
	public function getByTaskId(int $taskId): ?Im\Entity\Chat;
	/**
	 * Retrieve list of Chat IDs by corresponding task IDs.
	 *
	 * @param int[] $taskIds 
	 * @return int[] 
	 */
	public function findChatIdsByTaskIds(array $taskIds): array;
	public function findChatIdsByUserIdAndGroupIds(int $userId, array $groupIds): array;
	public function save(int $chatId, int $taskId): void;
	public function delete(int $taskId): void;
}
