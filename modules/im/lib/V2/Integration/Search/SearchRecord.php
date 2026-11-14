<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\V2\Integration\Search\Result\SnippetSegment;

/**
 * A row of the raw search output, reduced to what the facade reads from an index entry: the message
 * it addresses, its author and the ready snippet. The addressing fields of the entry (PARAM1, PARAM2
 * and the URL string) are deliberately absent - every identifier of the answer comes from the live
 * database, so the format of an entry does not reach the output.
 */
final readonly class SearchRecord
{
	/**
	 * @param SnippetSegment[] $snippet
	 */
	public function __construct(
		public int $messageId,
		public int $userId,
		public array $snippet,
	)
	{}
}
