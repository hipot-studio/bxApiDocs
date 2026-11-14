<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The main call button of the header of a group chat starts a call of Sync instead of a call of this module;
 * a private chat keeps the calls of this module.
 *
 * It has no legacy option, so it is not listed in {@see Registry}.
 */
final class SyncPrimaryCallFlag extends AbstractFlag
{
}
