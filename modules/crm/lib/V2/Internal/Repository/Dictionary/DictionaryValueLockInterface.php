<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;

/**
 * Mutual exclusion of the writes addressing one value of one dictionary - the pair (dictionary,
 * identifier) and nothing coarser: writes to different dictionaries, and to different values of the same
 * dictionary, never wait for each other.
 *
 * Taking and releasing belong to one collaborator, so nothing can hand over the one without the other.
 *
 * @internal
 */
interface DictionaryValueLockInterface
{
	/**
	 * Whether the writes of the value $id of the dictionary $type are ours for now. A caller that is
	 * answered `false` has to give its write up: it did not take the lock and must not release it.
	 */
	public function acquire(DictionaryType $type, string $id): bool;

	/**
	 * Allowed only after an {@see acquire()} that answered `true`, and with the very same pair: an
	 * implementation may bind resources for as long as the lock is held, and releasing one that was never
	 * taken leaves that binding unbalanced for everybody else.
	 */
	public function release(DictionaryType $type, string $id): void;
}
