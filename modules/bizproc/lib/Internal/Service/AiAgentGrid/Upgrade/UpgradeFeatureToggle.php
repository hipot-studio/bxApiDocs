<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade;

use Bitrix\Bizproc\Public\Feature\AiAgent\AgentUpgradeFlag;

/**
 * The single source of the dark-launch flag of the AI-agent upgrade: the row action and the
 * version column of the grid read the option through this class only, so the grid never reports
 * a new version while the way to install it is still hidden.
 *
 * This is not an access check. Template::upgradeAction() does not read the flag at all - it
 * checks the plan, the region, the administrator and the right to the copy on its own. The flag
 * governs the visibility of the interface, so it must never be used to withhold data nor to
 * authorize an upgrade.
 */
final class UpgradeFeatureToggle
{
	public static function isEnabled(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(AgentUpgradeFlag::class);
	}
}
