<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

class CounterCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Counter::class;
	}
}
