<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Grid;

use Bitrix\Socialnetwork\V2\Public\Dto\CounterCollection;

interface CounterProviderInterface
{
	public function get(array $groupIds, int $userId): CounterCollection;
}
