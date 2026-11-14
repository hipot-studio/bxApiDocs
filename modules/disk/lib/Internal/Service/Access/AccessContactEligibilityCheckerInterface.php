<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;

interface AccessContactEligibilityCheckerInterface
{
	/** @return array{id: int, name: string, avatar: string}|null */
	public function getEligibleContact(BaseObject $object, int $candidateId, int $currentUserId): ?array;
}
