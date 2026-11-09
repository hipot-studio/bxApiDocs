<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

final class ApplyLayoutCommand
{
	public function __construct(
		public readonly int $dashboardId,
		public readonly array $operations,
		public readonly ?string $version,
		public readonly bool $dryRun,
	)
	{
	}
}
