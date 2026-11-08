<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.dictionary.<name>.get`: which value to read and which of its fields to answer with.
 *
 * A request of its own rather than the standard {@see \Bitrix\Rest\V3\Interaction\Request\GetRequest},
 * which asks for the identifier without refusing an empty one: an empty identifier addresses nothing,
 * and the three methods addressing a value - this one, {@see UpdateValueRequest} and
 * {@see DeleteValueRequest} - answer it the same way.
 *
 * Which dictionary the value belongs to is not asked for: it is the trusted invariant of the route, and
 * a field of the body must not be able to name another one.
 */
final class GetValueRequest extends Request
{
	#[NotEmpty]
	public string $id;

	public ?SelectStructure $select = null;
}
