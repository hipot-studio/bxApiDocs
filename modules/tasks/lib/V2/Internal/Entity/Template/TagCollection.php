<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method getNamesList(): string[]
 */
class TagCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Tag::class;
	}
}
