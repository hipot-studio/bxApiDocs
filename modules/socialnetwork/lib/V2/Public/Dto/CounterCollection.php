<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto;

/**
 * @method Counter[] getIterator()
 */
class CounterCollection extends AbstractCollection
{
	protected static function getItemClass(): string
	{
		return Counter::class;
	}
}
