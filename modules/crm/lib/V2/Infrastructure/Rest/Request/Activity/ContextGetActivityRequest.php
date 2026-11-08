<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.<entity>.activity.get`: which activity to read and which of its fields to answer.
 *
 * The entity type is not a part of it: it is the one of the route, which the body cannot name. Nor is an owner,
 * since the route asks for a binding of the type and not for a binding to one object of it.
 */
final class ContextGetActivityRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;
}
