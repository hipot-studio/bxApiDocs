<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\MessageHistory;

final class AttachItem implements \JsonSerializable
{
	/**
	 * @param array $blocks Structured attachment data in REST format.
	 */
	public function __construct(
		public readonly array $blocks,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'blocks' => $this->blocks,
		];
	}
}
