<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

enum CatalogItemType: string
{
	case Application = 'application';
	case Bot = 'bot';

	/**
	 * @return array<string>
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
