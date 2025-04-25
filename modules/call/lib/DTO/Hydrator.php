<?php

namespace Bitrix\Call\DTO;

class Hydrator
{
	public function __construct(?\stdClass $fields = null)
	{
		if ($fields !== null)
		{
			$this->hydrate($fields);
		}
	}

	protected function hydrate(\stdClass $fields): void
	{
		foreach ($fields as $property => $value)
		{
			if (property_exists($this, $property))
			{
				$this->validateAndSet($property, $value);
			}
		}
	}

	private function validateAndSet(string $property, $value): void
	{
		$expectedType = gettype($this->$property);
		$actualType = gettype($value);

		if ($expectedType === $actualType)
		{
			$this->$property = $value;
		}
		else
		{
			switch ($expectedType)
			{
				case 'integer':
					$this->$property = (int)$value;
					break;
				case 'string':
					$this->$property = (string)$value;
					break;
				default:
					throw new \InvalidArgumentException("Invalid type for property {$property}. Expected {$expectedType}, got {$actualType}.");
			}
		}
	}
}
