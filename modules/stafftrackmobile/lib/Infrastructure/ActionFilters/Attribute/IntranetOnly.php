<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;

#[Attribute(Attribute::TARGET_METHOD)]
final class IntranetOnly implements FilterAttributeInterface
{
	public function getFilters(): array
	{
		return [new \Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\IntranetOnlyFilter()];
	}

	public function getType(): FilterType
	{
		return FilterType::Prefilter;
	}
}
