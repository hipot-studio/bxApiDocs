<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The body of a request as the client actually sent it, read in the two places where the parsing of the
 * framework is more forgiving than the contract of a method of the CRM.
 *
 * {@see Request::create()} walks the properties a request declares and takes the body apart by their
 * names. Everything that reaches it under any other name is dropped without a word, and a value under a
 * declared name is coerced to the declared type rather than measured against it. Both are reasonable
 * defaults for an API in general and wrong for a method that has to answer a mistake instead of acting on
 * a request it did not receive.
 *
 * The two readings below are the same reading: the body, literally. A key the request does not declare is
 * refused instead of dropped, and an identifier is the number the contract says it is rather than anything
 * a number can be made out of. Both are stated once here because a group of methods that stated them
 * itself would state them slightly differently from the next one, and a client would meet a strict method
 * and a forgiving one under the same prefix.
 *
 * ### A key that is not there and a key that was refused
 *
 * A refusal answers as the framework answers a field it does not know - the same exception, the same code,
 * the same message ({@see UnknownDtoPropertyException}). A client asking for something the contract does
 * not carry has met one wall, not two, and nothing in the answer says that this particular wall was built
 * by hand. That matters most for `filter`: the standard update and delete of REST v3 address a set by it,
 * and a method of the CRM addresses one record by `id` and nothing else - so a filter sent to one of them
 * is a key of a contract the method does not have, and is answered as one.
 *
 * A key present with a null value is left alone. That is where the framework stops taking a value for a
 * property it does declare, so it is where a key stops counting as sent: refusing what would have been
 * ignored anyway would make the two readings disagree.
 */
final class RequestRawBody
{
	/**
	 * Refuses the keys of the body a request has no property for, by the name the client sent them under.
	 *
	 * @param string $dtoClass the contract the method answers with, named in the refusal exactly as the
	 *        framework names it when refusing a field inside `fields`.
	 * @param string ...$keyNames the keys this request does not carry. Named rather than derived from the
	 *        properties of the request: the body of a request is not only what the request reads out of it,
	 *        and a key the transport may add on its own must not become a client error here.
	 * @throws UnknownDtoPropertyException for the first of the named keys the body carries.
	 */
	public static function refuseKeys(HttpRequest $httpRequest, string $dtoClass, string ...$keyNames): void
	{
		$body = $httpRequest->getJsonList()->getValues();

		foreach ($keyNames as $keyName)
		{
			if (isset($body[$keyName]))
			{
				throw new UnknownDtoPropertyException(self::dtoShortNameOf($dtoClass), $keyName);
			}
		}
	}

	/**
	 * Refuses an identifier that was not sent as a number.
	 *
	 * A property typed `int` is filled by the framework outside strict typing, so `"5"` reaches a request
	 * as `5` and a method built on it would answer a request no one made. The contract of the CRM names an
	 * identifier a number, and a client that sent a string is told which field and which type rather than
	 * being quietly understood.
	 *
	 * Called after the request is built, so an identifier that is missing altogether is already answered as
	 * a missing required field, and one that is zero or negative as a failed rule of the property.
	 *
	 * The type is named `int` rather than `integer` because that is the name the framework prints when a
	 * value cannot be coerced at all: one field must not report two different expectations of itself
	 * depending on which of the two checks happened to catch the value.
	 *
	 * @throws InvalidRequestFieldTypeException when the key holds anything but a JSON number without a
	 *         fractional part.
	 */
	public static function requireIntegerId(HttpRequest $httpRequest, string $keyName = 'id'): void
	{
		if (!is_int($httpRequest->getJsonList()->getRaw($keyName)))
		{
			throw new InvalidRequestFieldTypeException($keyName, 'int');
		}
	}

	/**
	 * The DTO is normally already known to the framework by the time a request is built; the reflection is
	 * for a caller that refuses a key before anything has read the contract.
	 */
	private static function dtoShortNameOf(string $dtoClass): string
	{
		return Structure::getDto($dtoClass)?->getShortName()
			?? (new \ReflectionClass($dtoClass))->getShortName();
	}
}
