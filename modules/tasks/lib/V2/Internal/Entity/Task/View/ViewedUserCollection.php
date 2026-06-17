<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task\View;

use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class ViewedUserCollection extends UserCollection
{
	public static function getEntityClass(): string
	{
		return ViewedUser::class;
	}
}
