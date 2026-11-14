<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\User;
use Bitrix\Main\Loader;

final class AccessContactEligibilityChecker implements AccessContactEligibilityCheckerInterface
{
	public function getEligibleContact(BaseObject $object, int $candidateId, int $currentUserId): ?array
	{
		if ($candidateId <= 0 || $candidateId === $currentUserId)
		{
			return null;
		}

		$user = User::loadById($candidateId);
		// Administrative status is not a rejection by itself: the provider only supplies candidates
		// connected to this object and excludes broad administrator access codes.
		if (
			$user === null
			|| $user->getActive() !== 'Y'
			|| !$user->isIntranetUser()
			|| $user->isBotUser()
			|| $user->isOpenlineUser()
			|| !$this->canOpenChat($currentUserId, $candidateId)
		)
		{
			return null;
		}

		$realObject = $object->getRealObject();
		$securityContext = $realObject->getStorage()?->getSecurityContext($candidateId);
		if ($securityContext === null || !$realObject->canChangeRights($securityContext))
		{
			return null;
		}

		return [
			'id' => $candidateId,
			'name' => $user->getFormattedName(),
			'avatar' => $user->getAvatarSrc(40, 40),
		];
	}

	private function canOpenChat(int $currentUserId, int $candidateId): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		// checkAccess() is called on the target dialog user and receives the viewer ID.
		return \Bitrix\Im\V2\Entity\User\User::getInstance($candidateId)
			->checkAccess($currentUserId)
			->isSuccess()
		;
	}
}
