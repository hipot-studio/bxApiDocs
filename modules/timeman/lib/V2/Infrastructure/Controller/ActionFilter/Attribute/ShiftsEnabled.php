<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\Attribute;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\ShiftsEnabledFilter;

#[Attribute(Attribute::TARGET_METHOD)]
final class ShiftsEnabled implements FilterAttributeInterface
{
	public function getFilters(): array
	{
		return [new ShiftsEnabledFilter()];
	}

	public function getType(): FilterType
	{
		return FilterType::Prefilter;
	}
}
