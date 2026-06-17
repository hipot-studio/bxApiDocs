<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\FullReport;

use Bitrix\Timeman\V2\Public\Dto\AbstractCollection;

final class FullReportCollection extends AbstractCollection
{
	protected static function getItemClass(): string
	{
		return FullReport::class;
	}
}
