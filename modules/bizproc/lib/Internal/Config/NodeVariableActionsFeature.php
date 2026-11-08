<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Node\NodeVariableActionsFlag;

/**
 * While the feature is off the variable activities declare no node_action role, so neither source of the
 * action list of a node offers them.
 */
final class NodeVariableActionsFeature
{
	public static function isEnabled(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(NodeVariableActionsFlag::class);
	}
}
