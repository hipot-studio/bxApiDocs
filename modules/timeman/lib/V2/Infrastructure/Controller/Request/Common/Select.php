<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common;

use Bitrix\Main\Provider\Params\SelectInterface;

final class Select
{
	/**
	 * @param string[] $fields
	 */
	public function __construct(
		public readonly array $fields = [],
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		$fields = [];

		foreach ($parameters as $field)
		{
			if (!is_string($field))
			{
				continue;
			}

			$field = trim($field);
			if ($field === '')
			{
				continue;
			}

			$fields[$field] = $field;
		}

		return new self(array_values($fields));
	}

	/**
	 * @param class-string<SelectInterface> $selectClass
	 */
	public function prepare(string $selectClass): SelectInterface
	{
		return new $selectClass($this->fields);
	}
}
