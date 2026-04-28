<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\Bot;

use Bitrix\Im\V2\Entity\EntityCollection;

class BotCollection extends EntityCollection
{
	public static function getRestEntityName(): string
	{
		return 'bots';
	}

	public static function initByBotIds(array $botIds, bool $isOwnerFormat = false): self
	{
		$collection = new self();

		foreach ($botIds as $botId)
		{
			$item = BotItem::createFromId((int)$botId, $isOwnerFormat);
			if ($item !== null)
			{
				$collection[] = $item;
			}
		}

		return $collection;
	}
}
