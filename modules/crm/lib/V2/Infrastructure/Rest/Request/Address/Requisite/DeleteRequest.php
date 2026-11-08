<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class DeleteRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $requisiteId;

	#[NotEmpty]
	#[PositiveNumber]
	public int $typeId;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		self::validateIdentifier($httpRequest);
		self::validateFilter($httpRequest);

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
}
