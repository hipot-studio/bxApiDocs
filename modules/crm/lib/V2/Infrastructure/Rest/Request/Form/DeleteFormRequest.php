<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.form.delete`: which form to remove, against which version, and whether the removal is meant.
 *
 * As in {@see UpdateFormRequest}, `id` names one form and there is no `filter`: removing a set is
 * not a hidden ability of a typical method of CRM, and a `filter` a client did send is refused
 * rather than ignored ({@see RefusesFilterAddressing}) - the more so here, where ignoring it would
 * delete the wrong thing.
 *
 * The expected version is the one {@see UpdateFormRequest} carries, read by the same mapper
 * ({@see FormRequestMapper::mapExpectedVersion()}), so a malformed value is answered the same way by
 * both methods. Here it is optional: a client removing the form it has just read guards the removal
 * with the version it read, and a client naming the form alone removes it as it always did. That is
 * what the nullable property with a default says to the hydrator of the framework - a property it
 * finds neither in the body nor nullable is answered as a missing required field.
 *
 * `confirmed` is the client saying the removal is meant even though the form is working. The
 * cascade is irreversible, so a working form is removed only when the request says so, and the
 * default is false because a removal nobody confirmed is exactly the one to be refused. An older
 * client, which knows neither property, removes a form that does not work the way it always has.
 *
 * Only a real boolean confirms, and {@see self::create()} is what holds the value to that: the
 * hydrator of the framework assigns a scalar to a typed property without strict typing, so `"N"`
 * and even `"false"` would otherwise reach the scenario as a confirmation nobody gave.
 */
final class DeleteFormRequest extends Request
{
	use RefusesFilterAddressing
	{
		create as private createRefusingFilterAddressing;
	}

	/** Answer to a request that addresses a set instead of one form. */
	public const FILTER_REFUSED = 'crm.form.delete removes one form addressed by id and takes no filter.';

	/** The property the confirmation of the removal arrives under. */
	private const CONFIRMATION = 'confirmed';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?string $updatedTime = null;

	public bool $confirmed = false;

	/**
	 * Refuses a confirmation that is not a boolean, by the raw value of the body: the property is
	 * typed, so by the time it is read the coercion has already happened. The refusal is the one the
	 * framework raises for a field of the wrong type, the way {@see FormRequestMapper} raises it for
	 * an unreadable version, so the answer names the property and stays the documented one.
	 *
	 * A body carrying no confirmation at all, and one carrying null, keep the default: those are
	 * the two the hydrator itself reads as an absent property, and neither says the removal is
	 * meant.
	 *
	 * @throws InvalidRequestFieldTypeException when `confirmed` carries anything but a boolean
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = self::createRefusingFilterAddressing($httpRequest, $dtoClass, $options);

		$confirmation = $httpRequest->getJsonList()->getRaw(self::CONFIRMATION);
		if ($confirmation !== null && !is_bool($confirmation))
		{
			throw new InvalidRequestFieldTypeException(self::CONFIRMATION, 'boolean');
		}

		return $request;
	}
}
