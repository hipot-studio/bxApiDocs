<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\AbstractRequisitePairDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractRequisitePairDto;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * A write of a requisite pair chosen in a CRM entity. Whose pair is written is the descendant's to
 * say: a group brings its own name, its own route and the mapper of its own contract.
 *
 * The identifier is the one of the entity that owns the choice: the stored pair has no identifier of
 * its own.
 *
 * The pair leaves this class as two non-negative integers, and nothing downstream normalizes it
 * again: a missing identifier and an explicit null both become zero here, because the scenario that
 * writes the pair reads zero as "nothing chosen" and does not know null at all.
 *
 * The pair is read while the request is being built, in a static call, so the mapper of the group is
 * asked for by a static hook rather than held as a field.
 *
 * @template TEntity of object the value class of the group
 */
abstract class AbstractRequisitePairSetRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public FieldsStructure $fields;

	private int $requisiteId = 0;

	private int $bankRequisiteId = 0;

	/** @return AbstractRequisitePairDtoMapper<TEntity> */
	abstract protected static function pairMapper(): AbstractRequisitePairDtoMapper;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): static
	{
		/** @var static $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!is_int($httpRequest->getJsonList()->getRaw('id')))
		{
			throw new InvalidRequestFieldTypeException('id', 'integer');
		}

		$rawFields = (array)$httpRequest->getJsonList()->getRaw('fields');
		if ($rawFields === [])
		{
			throw new RequestValidationException([
				new Error('The fields property must not be empty.', 'fields'),
			]);
		}

		foreach ($rawFields as $fieldName => $value)
		{
			if ($value !== null && !is_int($value))
			{
				throw new InvalidRequestFieldTypeException("fields.{$fieldName}", 'integer');
			}
		}

		/** @var AbstractRequisitePairDto $dto */
		$dto = $request->fields->convertToDto('set');
		$pair = static::pairMapper()->mapToEntity($dto);
		$request->requisiteId = $pair->getRequisiteId() ?? 0;
		$request->bankRequisiteId = $pair->getBankRequisiteId() ?? 0;

		return $request;
	}

	/** Zero clears the choice. */
	public function getRequisiteId(): int
	{
		return $this->requisiteId;
	}

	/** Zero clears the bank requisite alone. */
	public function getBankRequisiteId(): int
	{
		return $this->bankRequisiteId;
	}
}
