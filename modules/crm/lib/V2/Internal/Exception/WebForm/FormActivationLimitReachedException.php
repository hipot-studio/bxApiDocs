<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * The portal already keeps as many CRM forms working as its plan allows.
 *
 * Not a refusal of the input - the caller has nothing to correct in the request - and not a refusal
 * of a right. The numbers are not carried: the portal publishes how many forms work and how many it
 * may keep working through the dictionary of its configuration, and a second place naming the same
 * numbers would part with the first one at the first change.
 *
 * @internal
 */
final class FormActivationLimitReachedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The plan of the portal keeps no more CRM forms working.');
	}
}
