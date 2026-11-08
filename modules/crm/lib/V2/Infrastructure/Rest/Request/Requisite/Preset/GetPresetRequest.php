<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Preset;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.contact.requisite.preset.get` and `crm.company.requisite.preset.get`: which preset to read.
 *
 * One preset, named by its identifier, which is a number here rather than the string the standard
 * `GetRequest` calls it. The property is typed `int` and the raw body is read again for it, because the
 * framework fills a typed property outside strict typing and `"3"` would otherwise be understood instead
 * of refused - the same reading, for the same reason, as in the four methods over a requisite.
 *
 * A preset is addressed by its identifier and by nothing else, so a `filter` sent to this method is a key
 * of a contract it does not have and is refused as one ({@see RequestRawBody}) instead of being dropped in
 * silence. A `select` is the same kind of key here, and is refused the same way.
 *
 * ### Why `select` is declared when there is nothing to select
 *
 * No selection: a preset is answered whole. Its composition is what a client comes to this method for, and
 * it is read along with the preset rather than by a read of its own, so there is nothing here for a client
 * to save by naming less. The property exists for the framework rather than for the client.
 * {@see \Bitrix\Rest\V3\Controller\RestController::getActionResponse()} reads `$request->select` for every
 * answer that can carry relations - which both answers of this group are - and reads it plainly, without
 * asking whether the request has the property. A request that leaves it out therefore answers every
 * successful call with a warning about an undefined property: a line in the error log of the portal per
 * call, and a failure of a method that worked wherever warnings are turned into exceptions.
 *
 * Declaring it must not turn it into a parameter of the method, so it carries {@see Hidden}: the
 * machine-readable description of the API is built from the public properties of the request and skips the
 * hidden ones, so `select` stays out of the published contract. The schema of a method never carried
 * request properties at all.
 *
 * The declaration is what the framework needs; it is not an offer. A `select` sent by a client is refused
 * by name, as a `filter` is - accepting it would answer the whole preset to a client that believes it
 * asked for part of it. Only the framework may fill the property, and it does so for a
 * field-restricted scope, which is read from the scope rather than from the body and so is not refused.
 */
final class GetPresetRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	#[Hidden]
	public ?SelectStructure $select = null;

	/**
	 * @throws InvalidRequestFieldTypeException when the identifier was not sent as a number.
	 * @throws UnknownDtoPropertyException when the body carries a filter or a select.
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		RequestRawBody::requireIntegerId($httpRequest);
		RequestRawBody::refuseKeys($httpRequest, $dtoClass, 'filter', 'select');

		return $request;
	}
}
