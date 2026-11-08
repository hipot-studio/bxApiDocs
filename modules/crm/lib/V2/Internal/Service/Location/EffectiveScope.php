<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Location;

final class EffectiveScope
{
	public function __construct(
		public readonly array $filter,
		public readonly array $sort,
		public readonly array $select,
		public readonly array $runtime,
	)
	{
	}
}
