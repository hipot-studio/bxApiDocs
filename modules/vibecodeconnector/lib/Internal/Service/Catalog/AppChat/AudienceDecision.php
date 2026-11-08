<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

final class AudienceDecision
{
	/**
	 * @param int[] $withAccess
	 * @param int[] $undecided
	 */
	public function __construct(
		public readonly array $withAccess,
		public readonly array $undecided,
	) {}
}
