<?php

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\Internal\Entity\User;
use Bitrix\Main\EO_User;

class UserMapper
{
	public function convertFromOrm(EO_User $user): User
	{
		return new User(
			id: $user->getId(),
			active: $user->getActive(),
			login: $user->getLogin(),
			email: $user->getEmail(),
			name: $user->getName(),
			lastName: $user->getLastName(),
			timeZone: $user->getTimeZone(),
			languageId: $user->getLanguageId(),
			adminNotes: $user->getAdminNotes(),
		);
	}
}