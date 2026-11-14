<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Result;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Result;

/**
 * One portion of the output: the rows to deliver, the offset the next portion continues from and the
 * answer to the single question 'is there any point in asking for more'.
 */
class Portion extends Result
{
	public function __construct(
		private readonly MessageSearchItemCollection $items = new MessageSearchItemCollection(),
		private readonly int $nextOffset = 0,
		private readonly bool $hasNextPage = false,
	)
	{
		parent::__construct();
	}

	public static function error(Error $error): self
	{
		$portion = new self();
		$portion->addError($error);

		return $portion;
	}

	public function getItems(): MessageSearchItemCollection
	{
		return $this->items;
	}

	public function getNextOffset(): int
	{
		return $this->nextOffset;
	}

	public function hasNextPage(): bool
	{
		return $this->hasNextPage;
	}
}
