<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\User;

class UserGuest extends UserExternal
{
	public const AUTH_ID = 'im_guest';

	public function getType(): UserType
	{
		return UserType::GUEST;
	}
}
