<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;

/**
 * One requested mutation of a queue item - the per-row input of API-01
 * (updateItem and every row of updateItems), parsed and typed by the
 * controller. The field semantics (commit trigger, ownership CAS, release,
 * transportError) are those of {@see ItemMutationApplier::apply()}.
 */
final class ItemMutation
{
	public function __construct(
		public readonly int $itemId,
		public readonly ?string $tempToken = null,
		public readonly ?ItemStatus $status = null,
		public readonly ?string $ownerClientId = null,
		public readonly ?string $knownStaleClientId = null,
		public readonly bool $releaseOwnership = false,
		public readonly array $bizProcParameters = [],
		public readonly bool $bizProcParametersAreSigned = false,
		public readonly ?array $transportError = null,
	)
	{
	}

	/**
	 * Whether this row asks for a heartbeat and nothing else - the row of the volley the
	 * client sends on its keepalive tick (ownership.ts #sendKeepalive).
	 *
	 * The predicate is deliberately strict: a named stale owner is a takeover rather than a
	 * heartbeat, and an empty ownerClientId writes nothing on the per-row way (both
	 * {@see OwnershipService::heartbeat()} and acquire() refuse it), so neither belongs in
	 * a set the CAS treats uniformly.
	 */
	public function isKeepalive(): bool
	{
		return
			$this->ownerClientId !== null
			&& $this->ownerClientId !== ''
			&& ($this->knownStaleClientId ?? '') === ''
			&& $this->status === null
			&& $this->tempToken === null
			&& $this->transportError === null
			&& !$this->releaseOwnership
		;
	}

	/**
	 * Whether every row of the batch is a keepalive ({@see self::isKeepalive()}). A single
	 * row outside the shape - or anything that is not a parsed row - makes the whole batch
	 * something else, so a mixed batch keeps its per-row semantics exactly. An empty batch
	 * is no keepalive.
	 */
	public static function isKeepaliveBatch(array $mutations): bool
	{
		if ($mutations === [])
		{
			return false;
		}

		foreach ($mutations as $mutation)
		{
			if (!($mutation instanceof self) || !$mutation->isKeepalive())
			{
				return false;
			}
		}

		return true;
	}
}
