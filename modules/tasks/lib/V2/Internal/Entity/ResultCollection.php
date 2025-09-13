<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

/**
 * @method Result|null getFirstEntity()
 */
class ResultCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Result::class;
	}
}
