<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Template\PilotPublicationFlag;

/**
 * The single source of the rollout flag of the pilot publication: every gate of the feature reads the
 * option through this class only, so the write paths, the routing of a start and the editor never
 * disagree on whether the feature is on.
 *
 * The default is asymmetric on purpose: a write is refused while the feature is off, a read falls back
 * to the common version. Switching the feature off deletes no pilot data - switching it back on returns
 * every pilot with the audience it had.
 */
final class PilotPublicationFeature
{
	public const MODULE_ID = 'bizproc';

	public static function isEnabled(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(PilotPublicationFlag::class);
	}
}
