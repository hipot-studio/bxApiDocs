<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Report;

use Bitrix\Timeman\V2\Public\Dto\AbstractCollection;

final class ReportCollection extends AbstractCollection
{
	protected static function getItemClass(): string
	{
		return Report::class;
	}
}
