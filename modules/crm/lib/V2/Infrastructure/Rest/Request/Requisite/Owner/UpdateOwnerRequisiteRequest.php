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
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.contact.requisite.update` and `crm.company.requisite.update`: which requisite and what to write
 * into it.
 *
 * A request of its own rather than the standard `UpdateRequest` for the reason the whole CRM has one: the
 * standard one addresses a set by `filter`, and a typical method of the CRM changes one record named by
 * its `id`. The property is absent here rather than empty, and absence alone would not be enough - the
 * framework drops a key it has no property for without a word, so a client addressing a set the standard
 * way would be answered as if it had asked for something else entirely. The filter is refused by name
 * ({@see RequestRawBody}), and mass change stays what it is: a contract of its own, not an undocumented
 * corner of this one.
 *
 * The identifier is a number and is read as one. The property is typed `int`, which the framework fills
 * outside strict typing, so `"14"` would arrive as `14`; the raw body is read again for that reason.
 *
 * An empty `fields` is a request rather than an error of form: it asks for no write, and answering it
 * still takes finding the requisite and establishing the right to change it - which is the domain's to do,
 * not this class's. What may be written, and that the owner may not be, belongs to the DTO and to the
 * controller of the group.
 */
final class UpdateOwnerRequisiteRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public FieldsStructure $fields;

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
