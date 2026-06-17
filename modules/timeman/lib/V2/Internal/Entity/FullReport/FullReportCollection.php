<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\FullReport;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntityCollection;

final class FullReportCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return FullReport::class;
	}
}
