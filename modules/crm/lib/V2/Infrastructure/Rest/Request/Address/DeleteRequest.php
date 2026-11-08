<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.{contact|company}.address.delete`: one address, by its identifier.
 *
 * The identifier is read the way {@see GetRequest} reads it, and a filter is refused the way
 * {@see UpdateRequest} refuses it - both for the reasons written out there. Deleting a set of addresses is
 * not a hidden ability of this method; should it ever be needed, it is a contract of its own.
 */
final class DeleteRequest extends Request
{
	private const FILTER_PROPERTY = 'filter';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!is_int($httpRequest->getJsonList()->getRaw('id')))
		{
			throw new InvalidRequestFieldTypeException('id', 'integer');
		}

		if (array_key_exists(self::FILTER_PROPERTY, $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([
				new Error(
					'Filter is not supported here: the method addresses a single address by id.',
					self::FILTER_PROPERTY,
				),
			]);
		}

		return $request;
	}
}
