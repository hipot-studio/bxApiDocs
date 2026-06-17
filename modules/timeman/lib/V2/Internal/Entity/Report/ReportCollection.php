<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Report;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntityCollection;

final class ReportCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Report::class;
	}
}
