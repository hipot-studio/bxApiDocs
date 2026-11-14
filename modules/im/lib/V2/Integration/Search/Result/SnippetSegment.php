<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Result;

/**
 * Ready piece of the snippet: the clients only draw it, they neither cut, highlight nor decode.
 */
final readonly class SnippetSegment
{
	public function __construct(
		public string $text,
		public bool $highlighted,
	)
	{}

	public function toRestFormat(): array
	{
		return [
			'text' => $this->text,
			'highlighted' => $this->highlighted,
		];
	}
}
