<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Control\Mapper\Field;

class TagNamesMapper implements ValueMapperInterface
{
	public function getValue(mixed $value): ?string
	{
		return $value === null ? null : implode(',', $value);
	}
}
