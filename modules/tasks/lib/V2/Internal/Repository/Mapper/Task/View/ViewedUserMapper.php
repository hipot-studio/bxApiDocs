<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\View;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\View\ViewedUser;
use Bitrix\Tasks\V2\Internal\Entity\Task\View\ViewedUserCollection;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class ViewedUserMapper
{
	public function mapToCollection(UserCollection $users, array $viewedDates): ViewedUserCollection
	{
		$viewedUsers = new ViewedUserCollection();

		foreach ($users as $user)
		{
			$viewedDate = $viewedDates[$user->id] ?? null;
			if ($viewedDate instanceof DateTime)
			{
				$viewedUsers->add($this->mapToEntity($user, $viewedDate));
			}
		}

		return $viewedUsers;
	}

	public function mapToEntity(User $user, DateTime $viewedDate): ViewedUser
	{
		return new ViewedUser(
			id: $user->id,
			name: $user->name,
			type: $user->type,
			image: $user->image,
			gender: $user->gender,
			viewedTs: $viewedDate->getTimestamp(),
		);
	}
}
