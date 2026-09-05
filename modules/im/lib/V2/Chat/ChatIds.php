<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

/**
 * Resolved set of chat ids (unique, positive). Reusable wherever an endpoint
 * accepts a chatIds/dialogIds composition; an empty set means "clear".
 */
final class ChatIds
{
	/** @param int[] $values */
	public function __construct(
		public readonly array $values = [],
	) {}

	public function isEmpty(): bool
	{
		return $this->values === [];
	}
}
