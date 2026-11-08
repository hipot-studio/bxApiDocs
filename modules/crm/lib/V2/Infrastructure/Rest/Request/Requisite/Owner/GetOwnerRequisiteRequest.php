<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.contact.requisite.get` and `crm.company.requisite.get`: which requisite to read and how much of it.
 *
 * The identifier is a number here, not the string the standard `GetRequest` calls it, and it is read out of
 * the raw body as well: a property typed `int` is filled outside strict typing, so a string that looks like
 * a number would be understood instead of refused.
 *
 * The selection is the one of the CRM ({@see SelectStructure}) rather than the one of the framework, because
 * a requisite has collections hanging off it - its addresses and its bank details - and a client has to be
 * able to name one of them, or one field inside one. What a read that names nothing is answered is decided
 * by the rule the whole of the new REST of the CRM shares
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields}), and a collection is
 * never among what it falls back to: a collection costs a read of its own, and no client pays for one it did
 * not ask for.
 *
 * A `get` addresses one record by its identifier, so a filter sent to it is refused by name
 * ({@see RequestRawBody}), the same as in the change and the deletion of this group.
 */
final class GetOwnerRequisiteRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;

	/**
	 * @throws InvalidRequestFieldTypeException when the identifier was not sent as a number.
	 * @throws UnknownDtoPropertyException when the body carries a filter.
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		RequestRawBody::requireIntegerId($httpRequest);
		RequestRawBody::refuseKeys($httpRequest, $dtoClass, 'filter');

		return $request;
	}
}
