<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.{contact|company}.address.get`: one address, by its identifier.
 *
 * The identifier is an integer of the contract and is taken as one. The declared type alone does not say
 * that: the property is filled outside strict types, so a `"12"` would be coerced and accepted silently.
 * The raw value of the body is therefore looked at as well, the way the address group of a lead does it
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Lead\Address\GetRequest}).
 *
 * The owner is not part of the request: an address is addressed by an identifier that is unique across
 * owners, while the owner it must belong to is the one the route names. That an address of another owner,
 * of an owner the user may not read, and one that does not exist are all answered the same way is the
 * scenario's ({@see \Bitrix\Crm\V2\Public\Provider\Address\OwnerAddressProvider}).
 *
 * `select` is deliberately not published. The group answers the whole of an address, and an ability the
 * mapping does not have must not appear in the contract; publishing it later is a compatible change.
 */
final class GetRequest extends Request
{
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

		return $request;
	}
}
