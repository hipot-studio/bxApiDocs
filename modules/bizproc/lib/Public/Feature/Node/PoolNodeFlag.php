<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Feature\Node;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The rollout flag of the pool node: while it is off the node stays out of the catalog of the
 * designer, a node already placed in a template keeps working.
 */
final class PoolNodeFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
