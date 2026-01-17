<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Profile;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\UserRole;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

class BaseInfo implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly int $userId,
		public readonly string $fullName,
		public readonly UserRole $userRole,
		public readonly InvitationStatus $invitationStatus,
		public readonly ?int $photoId = null
	)
	{
	}

	public static function createByUserEntity(User $user): static
	{
		return new static(
			userId: $user->getId(),
			fullName: $user->getFormattedName(false, false),
			userRole: $user->getRole(),
			invitationStatus: $user->getInviteStatus(),
			photoId: $user->getPersonalPhoto(),
		);
	}

	public function getId(): int
	{
		return $this->userId;
	}

	public function getPhoto(int $size = 100): string
	{
		$userPhoto = \Bitrix\Intranet\Component\UserProfile::getUserPhoto(
			$this->photoId,
			$size,
		);

		return is_string($userPhoto) ? $userPhoto : '';
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'fullName' => $this->fullName,
			'userRole' => $this->userRole->value,
			'invitationStatus' => $this->invitationStatus->value,
			'photoId' => $this->photoId,
		];
	}
}
