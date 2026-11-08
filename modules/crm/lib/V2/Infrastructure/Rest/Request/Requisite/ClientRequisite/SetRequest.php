<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ClientRequisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\ClientRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\ClientRequisiteDto;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * The identifier is the one of the entity that owns the choice: the stored pair has no identifier of
 * its own.
 *
 * The pair leaves this class as two non-negative integers, and nothing downstream normalizes it
 * again: a missing identifier and an explicit null both become zero here, because the scenario that
 * writes the pair reads zero as "nothing chosen" and does not know null at all.
 */
final class SetRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public FieldsStructure $fields;

	private int $requisiteId = 0;

	private int $bankRequisiteId = 0;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
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

		/** @var ClientRequisiteDto $dto */
		$dto = $request->fields->convertToDto('set');
		$pair = (new ClientRequisiteDtoMapper())->mapToEntity($dto);
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
