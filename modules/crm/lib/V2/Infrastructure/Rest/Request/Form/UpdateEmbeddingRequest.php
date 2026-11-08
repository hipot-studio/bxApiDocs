<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.form.embedding.update`: which form and what to write into the settings of its embedding
 * variants.
 *
 * The embedding options have no entity of their own, so the form addresses them - the same `id` the
 * read of the embedding takes. The version is the version of that form: a form has one rule of
 * concurrent change, whichever of its contracts is writing.
 *
 * The rest is the form of {@see UpdateFormRequest}, for the same reasons: a `filter` a client did
 * send is answered rather than dropped in silence ({@see RefusesFilterAddressing}), empty `fields`
 * is a valid request asking for no write, and the expected version is carried as the string it
 * arrives as - read by {@see FormRequestMapper::mapExpectedVersion()}, which raises the refusal of
 * a field of the wrong type itself. The property is not nullable and has no default on purpose:
 * that is what makes the hydrator answer a request without it with the standard refusal of a
 * missing required field.
 */
final class UpdateEmbeddingRequest extends Request
{
	use RefusesFilterAddressing;

	/** Answer to a request that addresses a set instead of one form. */
	public const FILTER_REFUSED =
		'crm.form.embedding.update changes the embedding of one form addressed by id and takes no filter.'
	;

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public string $updatedTime;

	public FieldsStructure $fields;
}
