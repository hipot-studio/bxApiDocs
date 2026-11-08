<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Response\Form;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * The answer of a switch of the activity of a CRM form: the state the form is in after the call and
 * the version acting after it.
 *
 * One answer for turning a form on and for turning it off. Both calls change the same thing and a
 * client reads the outcome of both the same way; a switch that answered nothing would leave a
 * client that asked for a state it was already in unable to tell whether anything happened at all.
 *
 * The form itself is not projected here: the caller named the form, and a state is not a document.
 * Two values and no more - an answer that grew fields of the form would make a switch a second way
 * of reading one, and `crm.form.get` is that way.
 *
 * The author of the change is deliberately absent and must not be added. The update event of the
 * table never clears that column on a write turning a form off, so the row keeps the author of the
 * last time the form was turned on - an answer naming them would name a user who did not make this
 * change.
 *
 * The numbers of the limit of working forms are absent for a reason of their own: they belong to
 * `crm.form.configuration.get`, which answers with them whenever a client needs to show them, and
 * repeating them here would publish the same value in two contracts at once.
 *
 * Nothing of one resource is here, so the same answer serves any other switch of activity built the
 * same way. The version travels as the date it is: the envelope of REST v3 writes every date out in
 * the one format of this API, and a string built here would be a second format by hand.
 */
final class FormStateResponse extends Response
{
	public function __construct(public bool $active, public DateTime $updatedTime)
	{
	}
}
