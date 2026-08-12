<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

enum CatalogItemAccessType: string
{
	case Private = 'private';
	case Public = 'public';
	case ACL = 'acl';

	/**
	 * @return array<string>
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
