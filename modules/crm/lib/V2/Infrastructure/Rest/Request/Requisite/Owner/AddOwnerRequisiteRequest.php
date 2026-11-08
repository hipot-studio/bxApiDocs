<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.contact.requisite.add` and `crm.company.requisite.add`: the requisite to create.
 *
 * Everything the method takes is in `fields`, the owner among it - the route says which entity it serves,
 * and the contract of that route names the owner after it, `contactId` or `companyId`
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractOwnerRequisiteDto::ownerFieldName()}).
 * Which fields may be written, and that the owner and the preset are required among them, is the contract
 * of the DTO rather than of this class; the request carries the shape, and the shape is one property.
 *
 * A creation addresses nothing, so a `filter` sent to it is a key of a contract this method does not have
 * and is refused as one ({@see RequestRawBody}) instead of being dropped in silence. The whole group
 * answers a filter the same way, so that a client cannot learn from one method of it that a filter is
 * sometimes read.
 */
final class AddOwnerRequisiteRequest extends Request
{
	public FieldsStructure $fields;

	/**
	 * @throws UnknownDtoPropertyException when the body carries a filter.
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		RequestRawBody::refuseKeys($httpRequest, $dtoClass, 'filter');

		return $request;
	}
}
