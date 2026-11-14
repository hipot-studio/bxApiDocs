<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;

interface AccessContactCandidateSourceInterface
{
	public function getPersonalStorageOwnerId(BaseObject $object): ?int;

	public function getGroupId(BaseObject $object): ?int;

	public function getAuthorId(BaseObject $object): ?int;

	public function getGroupOwnerId(int $groupId): ?int;

	/** @return int[] USER_ID in ascending order */
	public function getGroupModeratorIds(int $groupId, int $limit): array;

	/**
	 * Rights of the full access task on the object, including the ones inherited from parent folders.
	 *
	 * @return list<array{ACCESS_CODE: string, NEGATIVE: int}>
	 */
	public function getFullAccessRights(BaseObject $object): array;
}
