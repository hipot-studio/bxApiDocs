<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Activity;

use Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * What every REST get of an activity does around {@see ActivityProvider}: turns the select of the client into the
 * names the provider and the mappers of the contract take, and a refusal of the provider into the answer of REST.
 */
final class ActivityReadingMapper
{
	/**
	 * The fields the client named, by the top-level names the provider and the mappers take, plus the field
	 * each relation is resolved by. No select at all means the set answered by default.
	 *
	 * @return string[]
	 */
	public static function selectedNames(?SelectStructure $select): array
	{
		if ($select === null)
		{
			return [];
		}

		$names = [];
		foreach ($select->getStructuredList() as $key => $value)
		{
			$names[] = is_int($key) ? $value : $key;
		}

		return array_values(array_unique(array_merge($names, $select->getRelationFields())));
	}

	/**
	 * The fields the client named inside each nested shape, by the name of the shape: `communications.type`
	 * asks the communications for their channel only. A shape named bare is expanded by REST to every field.
	 *
	 * @return array<string, string[]>
	 */
	public static function nestedNames(?SelectStructure $select): array
	{
		$nested = [];
		foreach ($select?->getStructuredList() ?? [] as $key => $value)
		{
			if (is_string($key) && is_array($value))
			{
				$nested[$key] = array_values(array_filter($value, 'is_string'));
			}
		}

		return $nested;
	}

	/**
	 * The standard not found of REST for the one refusal of the provider. A refusal the contract does not know
	 * is not a REST exception, so REST answers it as its internal error and keeps the message for the log.
	 */
	public static function refusalOf(Result $result, int $id): \Throwable
	{
		$error = $result->getErrors()[0] ?? null;
		if ($error?->getCode() === ActivityProvider::ERROR_NOT_FOUND)
		{
			return new EntityNotFoundException($id);
		}

		return new \RuntimeException($error?->getMessage() ?? 'The activity provider refused without an error.');
	}
}
