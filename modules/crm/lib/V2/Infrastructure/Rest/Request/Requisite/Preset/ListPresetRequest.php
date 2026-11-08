<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Preset;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestRawBody;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.contact.requisite.preset.list` and `crm.company.requisite.preset.list`: the presets a portal
 * offers, all of them.
 *
 * A list request without a filter, without a page and without a selection, and none of the three is an
 * omission.
 *
 * NO FILTER. The presets of a portal are the composition of a portal - a handful of records an
 * administrator maintains, not a set a client searches through - and not one field of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\PresetDto} is filterable. A property that could
 * never carry a condition would still be published in the machine-readable description of the method as
 * one that can, so there is none; and a filter that arrives anyway is refused by name
 * ({@see RequestRawBody}) rather than dropped in silence, which would answer the whole composition to a
 * client that believes it asked for part of it.
 *
 * NO PAGE. The read answers the composition entire
 * ({@see \Bitrix\Crm\V2\Public\Provider\Requisite\PresetProvider::getList()}); there is no window to move
 * and nothing for a second call to continue from. The group introduces no field of its own for paging, a
 * cursor or a total either.
 *
 * AN ORDER, THOUGH. Two fields of a preset are sortable - `sort`, which is the order the interface shows
 * them in, and `id`, which settles what `sort` leaves equal - and a contract that publishes them has to
 * be able to carry what a client names. The answer is complete and unwindowed, so ordering it takes
 * nothing away from the client that asked for none: it is the order of the interface either way.
 *
 * NO SELECT EITHER - AND A PROPERTY FOR IT ALL THE SAME. A preset is answered whole: its composition is
 * what a client comes to these methods for, it is read along with the preset, and there is nothing here
 * for a client to save by naming less. The property exists for the framework instead:
 * {@see \Bitrix\Rest\V3\Controller\RestController::getActionResponse()} reads `$request->select` for every
 * answer that can carry relations - which this one is - and reads it plainly, without asking whether the
 * request has the property, so a request that leaves it out answers every successful call with a warning
 * about an undefined property: a line in the error log of the portal per call, and a failure of a method
 * that worked wherever warnings are turned into exceptions.
 *
 * {@see Hidden} keeps the declaration from becoming a parameter of the method: the machine-readable
 * description of the API is built from the public properties of the request and skips the hidden ones, and
 * the schema of a method never carried request properties at all. A `select` sent by a client is refused
 * by name, as a `filter` is; only the framework may fill the property, and it does so for a
 * field-restricted scope, which is read from the scope rather than from the body and so is not refused.
 */
final class ListPresetRequest extends Request
{
	public ?ItemListOrderStructure $order = null;

	#[Hidden]
	public ?SelectStructure $select = null;

	/**
	 * @throws UnknownDtoPropertyException when the body carries a filter or a select.
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		RequestRawBody::refuseKeys($httpRequest, $dtoClass, 'filter', 'select');

		return $request;
	}
}
