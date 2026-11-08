<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * The request of the two dictionaries of scenarios, and it carries nothing.
 *
 * A dictionary is answered whole: its composition follows the composition of the portal rather than
 * the data of a user, so there is no projection to ask for, nothing to filter or sort by and no page
 * to take. The request still stands between the caller and the action - the body of the call reaches
 * the framework through it and a body that is not valid JSON is refused before the action runs.
 *
 * The dictionary of fields is the one of the three that costs enough to be worth narrowing, and it
 * has a request of its own for that.
 *
 * @see ListEntityFieldRequest
 */
final class ListFormDictionaryRequest extends Request
{
}
