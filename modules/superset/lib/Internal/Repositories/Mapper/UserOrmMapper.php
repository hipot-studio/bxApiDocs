<?php

namespace Bitrix\Superset\Internal\Repositories\Mapper;

use Bitrix\Superset\Internal\Entities\User;
use Bitrix\Superset\Internal\Models\EO_User;

final class UserOrmMapper
{
	public function convertFromOrm(EO_User $ormUser): User
	{
		return (new User())
			->setId($ormUser->getId())
			->setLogin((string)$ormUser->getLogin())
			->setAccessPassword($ormUser->getAccessPassword())
			->setServerId((int)$ormUser->getServerId())
			->setCreated($ormUser->getCreated())
			->setUpdated($ormUser->getUpdated())
			->setExternalId((int)$ormUser->getExternalId())
			->setClientId((string)$ormUser->getClientId());
	}

	public function convertToOrm(User $user): EO_User
	{
		$ormUser = $user->getId()
			? EO_User::wakeUp($user->getId())
			: new EO_User();

		$ormUser
			->setLogin($user->getLogin())
			->setServerId($user->getServerId())
			->setExternalId($user->getExternalId())
			->setClientId($user->getClientId());

		if ($user->getAccessPassword() === null)
		{
			$ormUser->unsetAccessPassword();
		}
		else
		{
			$ormUser->setAccessPassword($user->getAccessPassword());
		}

		if ($user->getCreated() !== null)
		{
			$ormUser->setCreated($user->getCreated());
		}

		if ($user->getUpdated() !== null)
		{
			$ormUser->setUpdated($user->getUpdated());
		}

		return $ormUser;
	}
}
