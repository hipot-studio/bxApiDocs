<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Type\BlockCode;

class EmployeeDynamicPlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		return NameHelper::create(
			BlockCode::EMPLOYEE_DYNAMIC,
			$fieldType,
			$party,
			$fieldCode,
		);
	}
}
