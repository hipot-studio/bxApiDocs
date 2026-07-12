<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync;

use Bitrix\Main\Result;

class RelationSyncResult extends Result
{
	public function __construct(
		public readonly bool $hasMore = false,
		public readonly ?int $nextOffset = null,
	)
	{
		parent::__construct();
	}
}
