<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Profile;

use Bitrix\Intranet\Entity\User;

class SubordinateInfo extends BaseInfo
{
	private string $position = '';

	public static function createByUserEntity(User $user): static
	{
		$info = parent::createByUserEntity($user);
		$info->position = trim($user->getWorkPosition() ?? '');

		return $info;
	}

	public function toArray(): array
	{
		$photo = $this->getPhoto(100);

		return [
			...parent::toArray(),
			'position' => $this->position,
			'avatarUrl' => $photo !== '' ? $photo : null,
		];
	}
}
