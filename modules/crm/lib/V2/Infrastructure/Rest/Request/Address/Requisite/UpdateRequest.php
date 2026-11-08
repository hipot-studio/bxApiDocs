<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Structure\FieldsStructure;

final class UpdateRequest extends AbstractWriteRequest
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $requisiteId;

	#[NotEmpty]
	#[PositiveNumber]
	public int $typeId;

	public FieldsStructure $fields;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		self::validateFields($httpRequest);
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		self::validateIdentifier($httpRequest);
		self::validateFilter($httpRequest);
		self::validateFieldsScope($request);
		$request->fields->convertToDto('update');

		return $request;
	}

	private static function validateIdentifier(HttpRequest $httpRequest): void
	{
		foreach (['requisiteId', 'typeId'] as $field)
		{
			if (!is_int($httpRequest->getJsonList()->getRaw($field)))
			{
				throw new InvalidRequestFieldTypeException($field, 'integer');
			}
		}
	}

	private static function validateFilter(HttpRequest $httpRequest): void
	{
		if (!array_key_exists('filter', $httpRequest->getJsonList()->getValues()))
		{
			return;
		}

		throw new RequestValidationException([
			new Error('Filter is not supported here: the method addresses a single address by identifier.', 'filter'),
		]);
	}

	private static function validateFields(HttpRequest $httpRequest): void
	{
		$fields = $httpRequest->getJsonList()->getValues()['fields'] ?? null;
		if (!is_array($fields))
		{
			throw new InvalidRequestFieldTypeException('fields', 'non-empty object');
		}
		if ($fields === [])
		{
			throw new RequestValidationException([
				new Error('At least one address field must be provided.', 'fields'),
			]);
		}

		foreach (['requisiteId', 'typeId'] as $field)
		{
			if (array_key_exists($field, $fields))
			{
				throw new RequestValidationException([
					new Error('The address identifier cannot be changed.', "fields.{$field}"),
				]);
			}
		}
	}
}
