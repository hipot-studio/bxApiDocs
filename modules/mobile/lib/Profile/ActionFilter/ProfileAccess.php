<?php

namespace Bitrix\Mobile\Profile\ActionFilter;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\FilterType;

#[Attribute(Attribute::TARGET_METHOD)]
class ProfileAccess implements \Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface
{
	public function __construct(
		private readonly FilterType $type = FilterType::EnablePrefilter,
	)
	{

	}

	public function getFilters(): array
	{
		if ($this->type->isNegative())
		{
			return [ProfileAccessControl::class];
		}

		return [new ProfileAccessControl()];
	}

	public function getType(): FilterType
	{
		return $this->type;
	}
}