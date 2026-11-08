<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.contact.requisite.delete` and `crm.company.requisite.delete`: which requisite to remove.
 *
 * One record, named by its identifier. The standard `DeleteRequest` would also take a `filter` and remove
 * everything it matched; that is the one shape of this contract a client must never reach by accident, so
 * the property is absent and a filter that arrives anyway is refused by name ({@see RequestRawBody})
 * rather than dropped in silence - a deletion carried out over something other than what the client wrote
 * is the worst of the ways to be forgiving.
 *
 * The identifier is a number and is read out of the raw body as such, for the same reason as in the change
 * of this group.
 */
final class DeleteOwnerRequisiteRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

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
