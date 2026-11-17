<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Release flag of the new regular task types (deadline / after_completion): the deadline
 * back-calculation, the completion handler (+ notification) and the type filter fields.
 *
 * Off is equivalent to today's behavior: only `schedule` regularity. It is a separate axis
 * both from {@see RegularTasksSectionFeature} and from the tariff feature `TASK_RECURRING_TASKS`.
 */
final class RegularTaskTypesFeature extends AbstractFlag
{
}
