<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Release flag of the "Regular tasks" section: menu item, page, its controllers and onboarding.
 *
 * Off is equivalent to today's behavior: no section. The section works without
 * {@see RegularTaskTypesFeature} too — every rule is then a schedule one. It is a separate axis
 * from the tariff feature `TASK_RECURRING_TASKS`.
 */
final class RegularTasksSectionFeature extends AbstractFlag
{
}
