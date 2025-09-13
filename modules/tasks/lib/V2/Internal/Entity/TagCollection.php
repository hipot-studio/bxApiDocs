<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

/**
 * @method array getNameList()
 */
class TagCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Tag::class;
	}
}