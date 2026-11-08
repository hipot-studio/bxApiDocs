<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

/**
 * What a user can be asked about an activity.
 *
 * There are five operations and no `add`: the right to create an activity is the right to update the CRM
 * object it will belong to, so it is asked of that object and not of an activity that does not exist yet
 * ({@see ActivityAccess::canAddToOwner()}).
 *
 * `Complete` and `Postpone` are not rights of their own in the CRM permission model - each one carries the
 * semantics of `Update` over the bound objects. They are separate cases because the provider of the
 * activity is asked about them by name and may answer differently than it would about an update.
 *
 * The backing values are part of the contract: a decision is remembered per user, activity and operation,
 * and the operation appears in the key.
 */
enum Operation: string
{
	case Read = 'read';
	case Update = 'update';
	case Delete = 'delete';
	case Complete = 'complete';
	case Postpone = 'postpone';
}
