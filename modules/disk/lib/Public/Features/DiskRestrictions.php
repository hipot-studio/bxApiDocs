<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * One switch for both restriction policies of Disk - the restricted formats and the personal
 * quotas. The tariff of the cloud and the edition of the box are checked by the availability
 * provider rather than here.
 */
final class DiskRestrictions extends AbstractFlag
{
}
