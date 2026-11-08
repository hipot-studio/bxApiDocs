<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\VibecodeBrandResolver;

class VibecodeBrandProvider
{
	public function __construct(
		private readonly VibecodeBrandResolver $brandResolver = new VibecodeBrandResolver(),
	) {
	}

	public function getDisplayName(): string
	{
		return $this->brandResolver->resolve()->getDisplayName();
	}
}
