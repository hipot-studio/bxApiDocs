<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Disk\Folder;

interface FolderSharingGateway
{
	/** @return array{id: int, taskName: string, type: int}|null */
	public function findRootSharing(Folder $folder, string $sharingEntity): ?array;

	public function add(Folder $folder, int $userId, string $sharingEntity, string $taskName): bool;

	/** @param array{id: int, taskName: string, type: int} $sharing */
	public function update(Folder $folder, array $sharing, string $taskName): bool;

	public function delete(int $sharingId, int $userId): bool;
}
