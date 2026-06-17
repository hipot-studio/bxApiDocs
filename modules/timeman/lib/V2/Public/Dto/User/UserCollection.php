<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\User;

use Bitrix\Timeman\V2\Public\Dto\AbstractCollection;

final class UserCollection extends AbstractCollection
{
	public static function mapFromArray(array $items): static
	{
		$users = [];
		foreach ($items as $item)
		{
			if ($item instanceof User)
			{
				$users[] = $item;
				continue;
			}

			if (is_array($item))
			{
				$users[] = User::mapFromArray($item);
			}
		}

		return new static(...$users);
	}

	protected static function getItemClass(): string
	{
		return User::class;
	}
}
