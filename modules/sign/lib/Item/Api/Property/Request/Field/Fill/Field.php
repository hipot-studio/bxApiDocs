<?php

namespace Bitrix\Sign\Item\Api\Property\Request\Field\Fill;

use Bitrix\Sign\Contract;

class Field implements Contract\Item
{
	public string $name;
	public FieldValuesCollection $value;
	public bool $trusted = false;
	public ?string $alias = null;

	public function __construct(
		string $name,
		FieldValuesCollection $value,
		bool $trusted = false,
		?string $alias = null,
	)
	{
		$this->name = $name;
		$this->value = $value;
		$this->trusted = $trusted;
		$this->alias = $alias;
	}
}
