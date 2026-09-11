<?php

namespace Bitrix\Sign\Item\B2e;

/**
 * Resolved recipient of a document receipt mark: the user id and the represented name to display.
 */
final readonly class ReceiptRecipient
{
	public function __construct(
		public int $id,
		public string $name,
	)
	{
	}
}
