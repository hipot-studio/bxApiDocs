<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.activity.get`: which activity to read and which of its fields to answer.
 *
 * A request of its own rather than the standard `GetRequest`, which types the identifier as a string while an
 * activity is addressed by a positive number. The select stays the standard structure of REST, the only shape
 * that hands the relations over for REST to load.
 */
final class GetActivityRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;
}
