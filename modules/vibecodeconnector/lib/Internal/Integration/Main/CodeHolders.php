<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

final class CodeHolders
{
	/**
	 * @param int[] $holders
	 */
	public function __construct(
		public readonly array $holders,
	) {}
}
