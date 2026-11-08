<?php

declare(strict_types=1);

namespace Bitrix\TimemanMobile\Infrastructure\Controllers\ActionFilters\Attributes;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\TimemanMobile\Infrastructure\Controllers\ActionFilters\IntranetOnlyFilter;

#[Attribute(Attribute::TARGET_METHOD)]
final class IntranetOnly implements FilterAttributeInterface
{
	public function getFilters(): array
	{
		return [new IntranetOnlyFilter()];
	}

	public function getType(): FilterType
	{
		return FilterType::Prefilter;
	}
}
