<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

class SubTemplateCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return SubTemplate::class;
	}
}
