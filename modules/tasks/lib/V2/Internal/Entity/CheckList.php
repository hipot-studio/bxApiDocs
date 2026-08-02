<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\CheckListItem;

/**
 * @method CheckListItem[] getIterator()
 */
class CheckList extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return CheckListItem::class;
	}
}
