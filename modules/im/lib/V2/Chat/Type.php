<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\FormatConverter;

class Type
{
	public function __construct(
		public readonly string $literal,
		public readonly ?string $entityType,
		public readonly string $extendedType,
	){}

	public function getExtendedType(bool $camelCase = true): string
	{
		if ($camelCase)
		{
			return FormatConverter::toCamelCase($this->extendedType);
		}

		return $this->extendedType;
	}

	public function allowsDynamic(): bool
	{
		return $this->literal === Chat::IM_TYPE_CHAT || $this->literal === Chat::IM_TYPE_OPEN;
	}
}
