<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

final class VibecodeUrlProvider
{
	public function __construct(
		private readonly Endpoint\VibecodeUrlProvider $urlProvider = new Endpoint\VibecodeUrlProvider(),
	) {
	}

	public function getDashboardUrl(?int $userId = null): string
	{
		return $this->urlProvider->getDashboardUrl($userId);
	}

}
