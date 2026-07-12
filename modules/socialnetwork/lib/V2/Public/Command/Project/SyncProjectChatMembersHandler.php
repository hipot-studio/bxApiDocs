<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Sync\ProjectChatMemberSyncService;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Result\SyncProjectChatMembersResult;

class SyncProjectChatMembersHandler
{
	public function __construct(
		private readonly ProjectChatMemberSyncService $projectChatMemberSyncService,
	)
	{

	}

	public function __invoke(SyncProjectChatMembersCommand $command): SyncProjectChatMembersResult
	{
		$internalResult = $this->projectChatMemberSyncService->syncChunk(
			groupId: $command->projectId,
			chatId: $command->chatId,
			chunkSize: $command->chunkSize,
			lastAddUserId: $command->lastAddUserId,
			lastDeleteUserId: $command->lastDeleteUserId,
		);

		return (new SyncProjectChatMembersResult())
			->addErrors($internalResult->getErrors())
			->setLastAddUserId($internalResult->getLastAddUserId())
			->setLastDeleteUserId($internalResult->getLastDeleteUserId())
			->setHasMore($internalResult->hasMore())
		;
	}
}
