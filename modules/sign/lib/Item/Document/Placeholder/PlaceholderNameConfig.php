<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Contract\Document\PlaceholderCollectorInterface;

final class PlaceholderNameConfig
{
	public function __construct(
		public readonly string $dataKey,
		public readonly PlaceholderCollectorInterface $strategy,
		public readonly int $party,
		public readonly string $resultKey,
	)
	{
	}
}
