<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter\Attribute\Rule;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EnableFilter implements FilterAttributeInterface
{
	public function __construct(
		private readonly array $filters,
	)
	{
	}

	public function getFilters(): array
	{
		return $this->filters;
	}

	public function getType(): FilterType
	{
		return FilterType::EnableFilter;
	}
}
