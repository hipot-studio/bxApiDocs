<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Common;

/**
 * Size of the page a tail collection took from the index.
 *
 * On MySQL the tail is read in two queries: the first one takes the ids of the page from the index,
 * the second one loads the rows. A row deleted between the two is missing from the collection, and
 * its size is then smaller than the page the index gave. Read as the page size, it would end the
 * list for a keyset client, and the rest of the tail would be lost for good.
 */
trait TailPageSizeTrait
{
	private ?int $tailPageSize = null;

	public function getTailPageSize(): int
	{
		return $this->tailPageSize ?? $this->count();
	}

	private function setTailPageSize(int $size): static
	{
		$this->tailPageSize = $size;

		return $this;
	}
}
