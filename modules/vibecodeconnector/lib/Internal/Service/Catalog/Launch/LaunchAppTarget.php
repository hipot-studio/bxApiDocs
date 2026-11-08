<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch;

final class LaunchAppTarget
{
	public function __construct(
		public readonly string $title,
		public readonly ?string $viewUrl,
		public readonly ?string $externalId,
		public readonly int $ownerId,
		public readonly ?string $ownerName,
		public readonly bool $isMine,
	) {}
}
