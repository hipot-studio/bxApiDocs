<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common\Collection;

trait CountableTrait
{
	abstract protected function &getArray(): array;

	public function count(): int
	{
		return count($this->getArray());
	}

	public function isEmpty(): bool
	{
		return empty($this->getArray());
	}
}
