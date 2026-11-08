<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\ArgumentException;

final class BankRequisiteTemplate
{
	/** @var array<string, bool> */
	private readonly array $availability;

	/**
	 * @param array<string, bool> $availability
	 * @throws ArgumentException
	 */
	public function __construct(array $availability)
	{
		$knownFields = array_column(BankRequisiteField::cases(), 'value');
		if (array_diff($knownFields, array_keys($availability)) !== []
			|| array_diff(array_keys($availability), $knownFields) !== []
		)
		{
			throw new ArgumentException('The bank requisite template must contain the complete field map.');
		}

		$normalizedAvailability = [];
		foreach ($knownFields as $field)
		{
			$available = $availability[$field];
			if (!is_bool($available))
			{
				throw new ArgumentException('Every bank requisite template value must be boolean.');
			}

			$normalizedAvailability[$field] = $available;
		}

		$this->availability = $normalizedAvailability;
	}

	public function has(BankRequisiteField $field): bool
	{
		return $this->availability[$field->value];
	}
}
