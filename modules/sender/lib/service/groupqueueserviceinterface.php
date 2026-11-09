<?php

namespace Bitrix\Sender\Service;

/**
 * Interface GroupQueueServiceInterface
 * @package Bitrix\Sender\Service
 * @internal Implemented by the module only: methods are added to it as the queue needs them.
 */
interface GroupQueueServiceInterface
{
	public function addToDB(int $type, int $entityId, int $groupId);
	
	public function releaseGroup(int $type, int $entityId, int $groupId);
	public function isEntityProcessed(int $type, int $entityId);

	public function isReleased(int $groupId): bool;

	public function isGroupHeld(int $groupId, ?int $exceptPostingId = null): bool;
}