<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Service\File\File;
use CFile;

class ResourceAvatarService
{
	public function handleAvatarCreate(Entity\Resource\Resource $resource): void
	{
		$loadedFile = $resource->getAvatar()?->getEncodedFile();
		if (!$loadedFile)
		{
			return;
		}

		$avatarId = $this->createAvatarFromBase64($loadedFile);
		if ($avatarId)
		{
			$resource->setAvatar(
				new Entity\File\File(id: $avatarId)
			);
		}
	}

	public function handleAvatarUpdate(Entity\Resource\Resource $newResource, int|null $currentAvatarId): void
	{
		$newAvatarId = $newResource->getAvatar()?->getId();
		$loadedFile = $newResource->getAvatar()?->getEncodedFile();

		if (!$loadedFile && ($currentAvatarId === $newAvatarId))
		{
			return;
		}

		if ($currentAvatarId)
		{
			CFile::Delete($currentAvatarId);
		}

		if (!$loadedFile)
		{
			return;
		}

		$avatarId = $this->createAvatarFromBase64($loadedFile);
		if ($avatarId)
		{
			$newResource->setAvatar(
				new Entity\File\File(id: $avatarId)
			);
		}
	}

	private function createAvatarFromBase64(string $encodedFile): ?int
	{
		$avatar = File::createImageFromBase64($encodedFile);
		CFile::ResizeImage($avatar, ['width' => 240, 'height' => 240], BX_RESIZE_IMAGE_EXACT);

		$avatar['MODULE_ID'] = 'booking';
		if (CFile::CheckImageFile($avatar))
		{
			return null;
		}

		$avatarId = CFile::SaveFile($avatar, 'booking', true);

		return is_int($avatarId) ? $avatarId : null;
	}
}
