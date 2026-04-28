<?php

namespace Bitrix\Crm\Multifield\Type;

use Bitrix\Crm\Multifield\Type;

final class Link extends Type
{
	public const ID = 'LINK';

	public const VALUE_TYPE_USER = 'USER';


	public function getValueTypes(): array
	{
		return [
			self::VALUE_TYPE_USER,
		];
	}
}
