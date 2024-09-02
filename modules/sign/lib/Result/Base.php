<?php

namespace Bitrix\Sign\Result;

use Bitrix\Main\Result;

abstract class Base extends Result
{
	public function getData(): array
	{
		$result = [];

		$class = new \ReflectionClass(static::class);
		$properties = $class->getProperties(\ReflectionProperty::IS_PUBLIC);
		foreach ($properties as $property)
		{
			$result[$property->getName()] = $property->getValue($this);
		}

		return $result;
	}

	public function getLastError(): ?\Bitrix\Main\Error
	{
		$errors = $this->getErrors();

		return array_pop($errors);
	}

	public function getFirstError(): ?\Bitrix\Main\Error
	{
		$errors = $this->getErrors();

		return array_shift($errors);
	}
}