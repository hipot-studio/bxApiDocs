<?php

declare(strict_types=1);

namespace Bitrix\AI\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Feature flag of the company limit: with it on, the limit wall of the portal shows the details of
 * the company limit taken from the limits service; with it off the portal wall stays simplified.
 * Read as Feature::isEnabled(CompanySubscriptionBilling::class, new Context()).
 *
 * The code of the feature is the fully qualified name of this class.
 *
 * It deliberately extends the package base and not Bitrix\AI\Analytics\ReleaseTrackedFlag and is
 * not an option of the module: the flag lives only in Main\Config\Feature.
 *
 * Never released, so it ships disabled and has no updater.
 */
final class CompanySubscriptionBilling extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
