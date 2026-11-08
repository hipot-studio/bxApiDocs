<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.form.deactivate`: which form to turn off.
 *
 * The same shape {@see ActivateFormRequest} has, and a class of its own rather than a shared one
 * with a state in it: the state belongs to the method, so a client that may turn forms off is never
 * one malformed value away from turning one on. The refusal of `filter` names the method that
 * refused ({@see RefusesFilterAddressing}), and that name differs here too.
 *
 * Turning a form off stops it for every page it is placed on at once, so a set is refused here for
 * the same reason it is refused everywhere else in the family.
 *
 * The expected version is optional and is read the way {@see ActivateFormRequest} reads it: one
 * property of the contract, one refusal of a malformed value, and the same meaning in both
 * directions of the switch.
 */
final class DeactivateFormRequest extends Request
{
	use RefusesFilterAddressing;

	/** Answer to a request that addresses a set instead of one form. */
	public const FILTER_REFUSED = 'crm.form.deactivate turns off one form addressed by id and takes no filter.';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?string $updatedTime = null;
}
