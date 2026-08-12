<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\AutoCheckInEnabledFilter;

#[Attribute(Attribute::TARGET_METHOD)]
final class AutoCheckInEnabled implements FilterAttributeInterface
{
	public function getFilters(): array
	{
		return [new AutoCheckInEnabledFilter()];
	}

	public function getType(): FilterType
	{
		return FilterType::Prefilter;
	}
}
