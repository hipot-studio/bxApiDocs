<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\User\Trait;

use Bitrix\Tasks\V2\Internal\Entity;

trait FormatUserTrait
{
	protected function formatUser(?Entity\User $user): string
	{
		if ($user === null)
		{
			return '';
		}

		return '[USER=' . $user->id . ']' . $user->name . '[/USER]';
	}

	protected function formatUserList(?Entity\UserCollection $users): string
	{
		if ($users === null)
		{
			return '';
		}

		return implode(', ', array_map([$this, 'formatUser'], $users->getEntities()));
	}
}
