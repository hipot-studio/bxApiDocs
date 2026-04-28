<?php

declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

use BackedEnum;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Dictionary;

/**
 * @template E of BackedEnum
 * @implements IDictionary<E>
 */
abstract class Blank implements IDictionary
{
	protected Dictionary $dictionary;

	/**
	 * @throws ObjectException
	 */
	public function __construct()
	{
		$this->dictionary = new Dictionary();

		$partClass = $this->getEnumPartClass();
		if (!enum_exists($partClass))
		{
			throw new ObjectException("Enum part class $partClass is not enum");
		}
	}

	/**
	 * @return class-string<E>
	 */
	abstract public function getEnumPartClass(): string;

	/**
	 * @param E&BackedEnum $name
	 * @param mixed $value
	 * @return static
	 * @throws ArgumentException
	 */
	public function set(BackedEnum $name, mixed $value): static
	{
		$partClass = $this->getEnumPartClass();
		if (!$name instanceof $partClass)
		{
			throw new ArgumentException("Parameter \$name must one of $partClass values");
		}

		$this->dictionary->set($name->value, $value);

		return $this;
	}

	/**
	 * @param E&BackedEnum $name
	 * @return mixed
	 * @throws ArgumentException
	 */
	public function get(BackedEnum $name): mixed
	{
		$partClass = $this->getEnumPartClass();
		if (!$name instanceof $partClass)
		{
			throw new ArgumentException("Parameter \$name must one of $partClass values");
		}

		return $this->dictionary->get($name->value);
	}

	public function isEmpty(): bool
	{
		return $this->dictionary->isEmpty();
	}

	public static function fromArray(array $data): static
	{
		$dictionary = new static;
		foreach ($data as $key => $value) {
			$enum = $dictionary->getEnumPartClass();
			$part = $enum::tryFrom($key);
			if ($part)
			{
				$dictionary->set($part, $value);
			}
		}

		return $dictionary;
	}

	public function toArray(): array
	{
		return $this->dictionary->getValues();
	}
}
