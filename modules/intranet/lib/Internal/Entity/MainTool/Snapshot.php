<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\MainTool;

class Snapshot
{
	public function __construct(
		public readonly string $siteId,
		public readonly ?int $userId,
		public readonly string $siteDir,
		public readonly CommonState $common,
		public readonly PersonalState $personal,
	)
	{
	}
}
