<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Internal\Service\Office\OfficesAvailability;

/**
 * The published name of {@see OfficesAvailability}: what the portal outside the module asks about the
 * switch of the office directory. The answer and the seams it is computed from are the ones of the
 * domain - this is the same question under the name its consumers know.
 */
class OfficesAvailabilityProvider extends OfficesAvailability
{
}
