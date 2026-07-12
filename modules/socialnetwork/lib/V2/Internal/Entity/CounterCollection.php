<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity;

/**
 * @method Counter[] getIterator()
 * @method Counter[] getEntities()
 */
class CounterCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Counter::class;
	}
}
