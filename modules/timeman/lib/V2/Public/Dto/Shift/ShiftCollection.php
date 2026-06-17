<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Shift;

use Bitrix\Timeman\V2\Public\Dto\AbstractCollection;

final class ShiftCollection extends AbstractCollection
{
	protected static function getItemClass(): string
	{
		return Shift::class;
	}
}
