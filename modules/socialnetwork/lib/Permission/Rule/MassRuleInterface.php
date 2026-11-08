<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission\Rule;

use Bitrix\Main\Access\AccessibleItem;

/**
 * Returns a result for every requested item.
 * An empty result means the request was empty.
 */
interface MassRuleInterface
{
	/**
	 * @param AccessibleItem[] $items
	 */
	public function executeMass(array $items, mixed $params = null): array;
}
