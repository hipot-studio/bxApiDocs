<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite\Param;

use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteField;
use Bitrix\Main\ArgumentException;

final class BankRequisiteSort
{
	/** @var array<string, string> */
	private array $order = [];

	/** @param array<string, string> $order */
	public function __construct(array $order = [])
	{
		foreach ($order as $field => $direction)
		{
			if (!in_array($field, self::allowedFields(), true))
			{
				throw new ArgumentException("Unknown bank requisite sort field: {$field}", 'order');
			}

			$this->order[$field] = strcasecmp($direction, 'DESC') === 0 ? 'DESC' : 'ASC';
		}
	}

	/** @return array<string, string> */
	public function getOrder(): array
	{
		$order = $this->order;
		if ($order === [])
		{
			$order['id'] = 'ASC';
		}
		elseif (!isset($order['id']))
		{
			$order['id'] = 'ASC';
		}

		return $order;
	}

	/** @return string[] */
	public static function allowedFields(): array
	{
		return [
			'id',
			'name',
			'comments',
			...array_column(BankRequisiteField::cases(), 'value'),
		];
	}
}
