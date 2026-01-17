<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template;

class TemplateCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Template::class;
	}
}
