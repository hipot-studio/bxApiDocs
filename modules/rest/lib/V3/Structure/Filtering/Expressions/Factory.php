<?php

namespace Bitrix\Rest\V3\Structure\Filtering\Expressions;


use Bitrix\Rest\V3\Exception\InvalidFilterException;

class Factory
{
	/**
	 * {expression: column, parameters: email}
	 * {expression: length, parameters: title}
	 *
	 * @param array $item
	 * @return Expression
	 * @throws InvalidFilterException
	 */
	public static function createFromArray(array $item): Expression
	{
		$item['parameters'] = (array)$item['parameters'];

		return match ($item['expression'])
		{
			'column' => new ColumnExpression(...$item['parameters']),
			'length' => new LengthExpression(...$item['parameters']),
			default => throw new InvalidFilterException($item),
		};
	}
}
