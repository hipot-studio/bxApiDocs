<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Rest\V3\Structure\UserFieldsTrait;

class UserFieldsDto extends Dto
{
	use UserFieldsTrait;

	public function __set(string $name, $value): void
	{
		if (str_starts_with($name, 'UF_'))
		{
			$this->userFields[$name] = $value;

			return;
		}
		parent::__set($name, $value);
	}

	public function __get(string $name): mixed
	{
		if (str_starts_with($name, 'UF_'))
		{
			return $this->userFields[$name] ?? null;
		}

		return parent::__get($name);
	}
}
