<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

class HistoryGridLogCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return HistoryGridLog::class;
	}
}
