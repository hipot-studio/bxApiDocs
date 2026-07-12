<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Scrum;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

class ScrumCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Scrum::class;
	}
}
