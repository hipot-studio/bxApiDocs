<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Node\OutgoingWebhookNodeFlag;
use Bitrix\Main\Config\Feature;

/**
 * The rollout flag of the outgoing webhook node: while it is off the node stays out of the catalog of
 * the designer, a node already placed in a template keeps working.
 */
final class OutgoingWebhookNodeFeature
{
	public static function isEnabled(): bool
	{
		return Feature::isEnabled(OutgoingWebhookNodeFlag::class);
	}
}
