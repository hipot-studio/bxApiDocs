<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

class Filter
{
	public const ALLOWED_OPERATORS = [
		'=',
		'!=',
		'>',
		'>=',
		'<',
		'<=',
		'in',
		'like',
	];

	protected array $list = [];

	public function __construct(array $list = [])
	{
		foreach ($list as [$field, $operator, $value])
		{
			if (!$field instanceof Field
				|| !in_array($operator, self::ALLOWED_OPERATORS, true)
			)
			{
				continue;
			}

			$this->list[] = [$field, $operator, $value];
		}
	}

	public function getList(): array
	{
		return $this->list;
	}

	public function getUserIdForAccessCheck(): ?int
	{
		foreach ($this->getList() as [$field, $operator, $value])
		{
			if ($field === Field::AccessUserId)
			{
				return (int)$value;
			}
		}

		return null;
	}
}