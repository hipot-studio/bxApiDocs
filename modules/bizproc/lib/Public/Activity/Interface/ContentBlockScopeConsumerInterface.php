<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

/**
 * Implemented by activities whose content block may resolve a label from another activity's scope
 * contribution (e.g. storage read/write/delete nodes show a dynamic storage title declared by a
 * CreateStorageNode). The descriptor lets the editor client resolve the cross-node label reactively,
 * without a server round-trip, for on-canvas producers. Display-only.
 */
interface ContentBlockScopeConsumerInterface
{
	/**
	 * @return array{namespace: string, keyProperty: string, emptyLabel: string}
	 *   namespace   — scope namespace to resolve the label in (e.g. "storage")
	 *   keyProperty — Properties key holding the lookup key (e.g. "StorageCode")
	 *   emptyLabel  — text shown when the matched producer's label is empty
	 */
	public static function getScopeConsumption(): array;
}
