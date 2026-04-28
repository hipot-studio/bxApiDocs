<?php

namespace Bitrix\Ldap;

final class Cursor
{
	public function __construct(
		public readonly ?int $pageSize = null,
		public readonly string $cookie = '',
	)
	{
	}
}