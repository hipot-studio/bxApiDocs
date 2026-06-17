<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Shift;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntityCollection;

final class ShiftCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Shift::class;
	}
}
