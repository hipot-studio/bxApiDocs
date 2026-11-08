<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;

/**
 * A signing document a process has created. The right to see the process and the right to see the document
 * are counted apart, so a document of a row may come without a name and without a way to open it: the fact
 * that the process reached signing belongs to the process, the contents of the document do not.
 */
final class ProcessDocument implements Contract\Item
{
	public function __construct(
		public readonly int $id,
		public readonly string $title = '',
		public readonly string $statusName = '',
		public readonly ?string $url = null,
	)
	{
	}
}
