<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource;

final class Policy
{
	public function __construct(
		private readonly Settings $settings,
	) {
	}

	public function isVibecodeSource(): bool
	{
		$value = $this->settings->getValue();

		return $value === Settings::VIBECODE;
	}
}
