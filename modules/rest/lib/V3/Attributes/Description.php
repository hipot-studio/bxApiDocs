<?php

namespace Bitrix\Rest\V3\Attributes;

use Bitrix\Main\Localization\LocalizableMessage;

#[\Attribute]
class Description extends AbstractAttribute
{
	public function __construct(public readonly LocalizableMessage|string $value)
	{
	}
}
