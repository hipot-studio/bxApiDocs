<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.{contact|company}.address.add`: the fields of a new address, the owner it belongs to among them.
 *
 * The fields are the whole of what an address is created from, and every rule about them is stated where it
 * belongs rather than here: that the owner and the type of the address have to be given, and that no other
 * part of the identity may be, is the contract of the route
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator} declares both required and
 * writable in `add` alone), and that the address itself must not be empty is a rule of the domain the write
 * path of V2 answers.
 *
 * The owner travels inside `fields`, the place the parent of an added child belongs to, under the name the
 * route publishes it as - `contactId` or `companyId`. The type of the owner is not part of the request at
 * all: it is the trusted invariant of the method name.
 */
final class AddRequest extends Request
{
	public FieldsStructure $fields;
}
