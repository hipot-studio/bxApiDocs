<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.dictionary.<name>.delete`: which value to remove.
 *
 * As in {@see UpdateValueRequest}, one non-empty identifier addresses the value and there is no `filter`:
 * removing a set is not a hidden ability of a typical method of CRM. A `filter` sent anyway is refused by
 * the controller of the group - leaving it out of here only makes it lost, not refused.
 */
final class DeleteValueRequest extends Request
{
	#[NotEmpty]
	public string $id;
}
