<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\RelationSyncResult;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\StructureSyncService;

class StructureSyncReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		$service = $this->getService();

		$result = match (true)
		{
			$message instanceof RelationAddedMessage => $service->handleRelationAdded(
				nodeId: $message->nodeId,
				entityId: $message->entityId,
				createdBy: $message->createdBy,
				withChildNodes: $message->withChildNodes,
				offset: $message->offset,
			),
			$message instanceof RelationDeletedMessage => $service->handleRelationDeleted(
				nodeId: $message->nodeId,
				entityId: $message->entityId,
				createdBy: $message->createdBy,
				withChildNodes: $message->withChildNodes,
				offset: $message->offset,
			),
			$message instanceof MemberAddedMessage => $service->handleMemberAddedAsync(
				nodeId: $message->nodeId,
				userId: $message->userId,
				offset: $message->offset,
			),
			$message instanceof MemberDeletedMessage => $service->handleMemberDeletedAsync(
				nodeId: $message->nodeId,
				userId: $message->userId,
				offset: $message->offset,
			),
			$message instanceof LegacyDepartmentDeletedMessage => $service->handleLegacyDepartmentDeleted(
				collabId: $message->collabId,
				departmentId: $message->departmentId,
				initiatorId: $message->initiatorId,
			),
			default => throw new UnprocessableMessageException($message),
		};

		if ($result instanceof RelationSyncResult && $result->hasMore && $message instanceof AbstractRelationMessage)
		{
			$this->enqueueNextBatch($message, $result->nextOffset);
		}
	}

	protected function enqueueNextBatch(AbstractRelationMessage $message, int $nextOffset): void
	{
		$message->withOffset($nextOffset)->sendToQueue();
	}

	protected function getService(): StructureSyncService
	{
		return Container::getInstance()->getStructureSyncService();
	}
}
