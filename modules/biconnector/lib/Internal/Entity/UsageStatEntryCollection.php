<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @method UsageStatEntry|false current()
 * @method UsageStatEntry|null offsetGet($offset)
 */
class UsageStatEntryCollection extends EntityCollection
{
	protected static function getEntityClass(): string
	{
		return UsageStatEntry::class;
	}
}
