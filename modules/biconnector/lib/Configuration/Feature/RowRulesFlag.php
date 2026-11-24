<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Configuration\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The rollout switch of the rules on rows. Off by default.
 *
 * Asked through Configuration\Feature::isRowRulesEnabled() only: the rules ride on the table permission
 * axis, and that conjunction lives there.
 */
final class RowRulesFlag extends AbstractFlag
{
}
