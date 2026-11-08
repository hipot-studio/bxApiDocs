<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Main\Validation\Rule\AtLeastOnePropertyNotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.form.validate`: which form to check, which configuration to check, or both.
 *
 * Both properties are optional and one of them is enough: `id` alone checks the form as it is
 * stored, `fields` alone checks a configuration that does not exist yet, and the two together
 * check that configuration on top of the stored form. A request carrying neither names nothing to
 * check and is refused here rather than answered with a verdict about nothing.
 *
 * `fields` is the very document {@see UpdateFormRequest} carries, read by the same mapper: a check
 * that took another document would answer about an input the write would never see. The structure
 * is nullable and defaults to null because that is what makes the hydrator of the framework leave
 * it alone: a property it finds neither in the body nor nullable is answered as a missing required
 * field, and one it does find is built into the structure as everywhere else. Absent and empty are
 * told apart here, so `{"fields": {}}` still asks about a document - an empty one.
 *
 * Neither property carries a rule of its own. A rule is run against the value as it stands, null
 * included, so demanding a positive `id` here would refuse every request that names no form - the
 * very requests this method exists for. What may not be left out at all is the pair as a whole, and
 * that is the business of the class rule above.
 *
 * As in the rest of the family, one form is named by `id` and a `filter` is refused rather than
 * ignored ({@see RefusesFilterAddressing}).
 */
#[AtLeastOnePropertyNotEmpty(propertyNames: ['id', 'fields'], showPropertyNames: true)]
final class ValidateFormRequest extends Request
{
	use RefusesFilterAddressing;

	/** Answer to a request that addresses a set instead of one form. */
	public const FILTER_REFUSED = 'crm.form.validate checks one form addressed by id and takes no filter.';

	public ?int $id = null;

	public ?FieldsStructure $fields = null;
}
