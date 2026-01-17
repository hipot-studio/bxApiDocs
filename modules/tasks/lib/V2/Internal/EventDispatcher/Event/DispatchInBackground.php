<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\Event;

/**
 * @method int getBackgroundPriority() Get the priority of the event. Bitrix\Main\Application::JOB_PRIORITY_NORMAL by default.
 *
 * @see Bitrix\Main\Application::JOB_PRIORITY_NORMAL
 * @see Bitrix\Main\Application::JOB_PRIORITY_LOW
 */
interface DispatchInBackground
{
}
