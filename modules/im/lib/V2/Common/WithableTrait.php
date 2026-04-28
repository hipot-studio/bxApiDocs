<?php

namespace Bitrix\Im\V2\Common;

trait WithableTrait
{
	public function with(mixed ...$values): static
	{
		$ref = new \ReflectionClass($this);
		$args = [];

		foreach ($ref->getConstructor()->getParameters() as $param)
		{
			$name = $param->getName();

			if (array_key_exists($name, $values))
			{
				$args[$name] = $values[$name];
			}
			else
			{
				$args[$name] = $this->$name;
			}
		}

		return new static(...$args);
	}
}