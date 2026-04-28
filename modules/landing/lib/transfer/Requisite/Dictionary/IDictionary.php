<?php

declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

use BackedEnum;
/**
 * @template E of BackedEnum
 */
interface IDictionary
{
	/**
	 * @return class-string<E>
	 */
	public function getEnumPartClass(): string;

	/**
	 * @param E&BackedEnum $name
	 * @param mixed $value
	 * @return IDictionary
	 */
	public function set(BackedEnum $name, mixed $value): static;

	/**
	 * @param E&BackedEnum $name
	 * @return mixed
	 */
	public function get(BackedEnum $name): mixed;

	/**
	 * Check is has no value
	 * @return bool
	 */
	public function isEmpty(): bool;
}
