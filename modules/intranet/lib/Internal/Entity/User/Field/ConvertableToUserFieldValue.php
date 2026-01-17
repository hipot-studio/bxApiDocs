<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

interface ConvertableToUserFieldValue
{
	public function toUserFieldValue(): string;
}
