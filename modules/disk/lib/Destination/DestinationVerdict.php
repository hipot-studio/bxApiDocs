<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

/**
 * Answer of the domain to the question "what happens to this object in this folder".
 *
 * A name conflict is a hint, not a decision: it never blocks the operation, the executor still resolves it.
 *
 * The reasons below are a closed contract: the client validates the field against the very same list and
 * refuses the whole answer on an unknown value, which leaves the level of folders undrawn. A refusal that
 * has no reason here belongs to the error of the action, not to this list.
 */
final class DestinationVerdict
{
	public const REASON_NO_RIGHTS = 'no_rights';
	public const REASON_STORAGE_RESTRICTED = 'storage_restricted';
	public const REASON_NO_SPACE = 'no_space';
	public const REASON_FOLDER_GONE = 'folder_gone';
	public const REASON_ALREADY_HERE = 'already_here';
	public const REASON_SOURCE_SUBTREE = 'source_subtree';

	/**
	 * A reason of a selection only: a single object behind a link is refused by the error of the action
	 * (@see VerdictResolver::isOutOfReachAsLink), and the clients of the single form never learned this value.
	 */
	public const REASON_LINK_UNSUPPORTED = 'link_unsupported';

	private function __construct(
		public readonly bool $allowed,
		public readonly ?string $reason,
		public readonly bool $nameConflict,
		/** the object of a selection the refusal belongs to; null for a refusal of the folder itself */
		public readonly ?int $blockingObjectId = null,
	)
	{
	}

	public static function allow(bool $nameConflict): self
	{
		return new self(true, null, $nameConflict);
	}

	public static function deny(string $reason): self
	{
		return new self(false, $reason, false);
	}

	public static function denyFor(string $reason, ?int $blockingObjectId): self
	{
		return new self(false, $reason, false, $blockingObjectId);
	}

	public function toArray(): array
	{
		return [
			'allowed' => $this->allowed,
			'reason' => $this->reason,
			'nameConflict' => $this->nameConflict,
		];
	}

	/**
	 * The answer for a selection of objects. The single form stays without the object on purpose: the strict
	 * mappers of the clients refuse an answer with a key they do not know.
	 */
	public function toSelectionArray(): array
	{
		return [
			...$this->toArray(),
			'blockingObjectId' => $this->blockingObjectId,
		];
	}
}
