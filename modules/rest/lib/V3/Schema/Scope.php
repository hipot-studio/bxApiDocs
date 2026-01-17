<?php

namespace Bitrix\Rest\V3\Schema;

class Scope
{
	public function __construct(public readonly string $path, public readonly array $fields = [])
	{
	}
}
