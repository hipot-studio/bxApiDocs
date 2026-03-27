<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Profile;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Entity\IdentifiableEntityCollection;

class BaseInfoCollection extends IdentifiableEntityCollection
{
	protected static function getEntityClass(): string
	{
		return BaseInfo::class;
	}

	public static function createByUserCollection(UserCollection $userCollection): BaseInfoCollection
	{
		$baseInfoCollection = new BaseInfoCollection();

		$userCollection->forEach(function (User $user) use ($baseInfoCollection) {
			$baseInfoCollection->add(BaseInfo::createByUserEntity($user));
		});

		return $baseInfoCollection;
	}
}
