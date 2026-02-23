<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema\Builder;

interface IBuilder
{
	/**
	 * Builds a final schema representation from a structured schema description.
	 *
	 * @return array Fully built schema representation.
	 */
	public function build(): array;
}
