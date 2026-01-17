<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

class SystemHistoryLogCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return SystemHistoryLog::class;
	}
}
