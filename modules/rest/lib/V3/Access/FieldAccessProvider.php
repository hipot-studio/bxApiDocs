<?php

namespace Bitrix\Rest\V3\Access;

use Bitrix\Main\Result;

/**
 * Module policy for the field methods of a resource. Declared by the FieldAccess attribute
 * on the resource DTO.
 *
 * The decision is a value, not a transport exception: a failed Result carries the reason
 * and is turned into an access error by the filter.
 */
interface FieldAccessProvider
{
	public function decide(FieldAccessContext $context): Result;
}
