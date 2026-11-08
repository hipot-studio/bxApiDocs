<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.form.update`: which form and what to write into it.
 *
 * A request of its own rather than the standard `UpdateRequest`, which also addresses a set by
 * `filter`. A typical method of CRM may not do that, so the property is absent here - and a `filter`
 * a client did send is answered rather than dropped in silence ({@see RefusesFilterAddressing}).
 *
 * Empty `fields` is a valid request rather than an error: it asks for no write, and answering it
 * still takes checking that the form exists and may be changed.
 *
 * The expected version is carried as the string it arrives as: the generic request hydrator assigns
 * scalar properties without converting them, and a property typed as the date would only turn every
 * malformed value into the same refusal the framework raises for a wrong type. Reading the date out
 * of the string is {@see FormRequestMapper::mapExpectedVersion()}, which raises that very refusal
 * itself. The property is not nullable and has no default on purpose: that is what makes the
 * hydrator answer a request without it with the standard refusal of a missing required field.
 */
final class UpdateFormRequest extends Request
{
	use RefusesFilterAddressing;

	/** Answer to a request that addresses a set instead of one form. */
	public const FILTER_REFUSED = 'crm.form.update changes one form addressed by id and takes no filter.';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public string $updatedTime;

	public FieldsStructure $fields;
}
