<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class LayoutName
{
	private const MAX_LENGTH = 100;

	private function __construct(private readonly string $value)
	{
	}

	/**
	 * Creates a custom layout name.
	 */
	public static function create(string $value): Result
	{
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > self::MAX_LENGTH)
		{
			return (new Result())->addError(
				new Error('The layout name is invalid.', 'INVALID_LAYOUT_CONFIGURATION'),
			);
		}

		return (new Result())->setData([
			'name' => new self($value),
		]);
	}

	/**
	 * Returns the normalized layout name.
	 */
	public function getValue(): string
	{
		return $this->value;
	}
}
