<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary;

use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * What the two writing requests of the group share: the set of fields to write, and the rule that a
 * client may write only the fields its grant names.
 *
 * A field-restricted area of access - `crm[name:sort]` - reaches an action as the `fields` of the
 * {@see \Bitrix\Rest\V3\Schema\Scope} the request is built with, and the platform reads them for the
 * `select` of a reading request alone ({@see Request::create()}). Nothing of the platform looks at the
 * `fields` of a write, so an application granted `crm[name]` would write `id` and `sort` all the same.
 * The right of the domain is no answer to that: it says what the *user* may do, while a grant says what
 * the application may do on their behalf, and the two are checked in different places.
 *
 * The check stands where the fields of a request are built rather than in the controller, and it is a
 * check of one shape with the ones the item and the requisites of CRM do
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AbstractItemRequest},
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Requisite\AbstractWriteRequest}) - the same
 * refusal for the same reason, so a client granted a part of the fields is answered the same way
 * wherever it writes. Inheriting either of those two is not on offer: an item request rebuilds the set
 * of fields around custom fields the dictionaries have none of, and a requisite one belongs to another
 * resource.
 */
abstract class AbstractWriteValueRequest extends Request
{
	public FieldsStructure $fields;

	/**
	 * Refuses a field the grant of the caller does not name.
	 *
	 * An unrestricted grant - the usual one - names no field at all, and then every field of the contract
	 * is writable: an empty list is "no restriction" and not "nothing may be written", which is how the
	 * platform reads it for a `select` too.
	 *
	 * The refusal is the one the platform answers an unknown field of a DTO with, and that is deliberate:
	 * a field outside the grant is a field the caller has no contract for, and an answer telling it apart
	 * from a misspelled one would say which fields the portal has beyond the grant.
	 */
	protected static function validateFieldsScope(self $request): void
	{
		$availableFields = ($request->getOptions()['scope'] ?? null)?->fields ?? [];
		if ($availableFields === [])
		{
			return;
		}

		$dto = Structure::getDto($request->getDtoClass());
		if ($dto === null)
		{
			return;
		}

		$fields = array_merge($request->fields->getItems(), $request->fields->getUserFields());
		foreach (array_keys($fields) as $fieldName)
		{
			if (!in_array($fieldName, $availableFields, true))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), (string)$fieldName);
			}
		}
	}
}
