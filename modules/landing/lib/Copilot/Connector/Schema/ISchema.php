<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

interface ISchema
{
	/**
	 * Returns the complete schema including name and strict flag.
	 *
	 * @return array Fully built schema representation as an associative array.
	 */
	public function getSchema(): array;
}
