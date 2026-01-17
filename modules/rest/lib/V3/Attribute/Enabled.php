<?php

namespace Bitrix\Rest\V3\Attribute;

use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

#[\Attribute]
class Enabled extends AbstractAttribute
{
	protected CheckEnabledProvider $provider;

	public function __construct(string $provider)
	{
		if (!class_exists($provider))
		{
			throw new \InvalidArgumentException("Provider class '$provider' does not exist");
		}

		if (!$provider instanceof CheckEnabledProvider)
		{
			throw new \InvalidArgumentException("Provider class '$provider' does not implement CheckEnabledProvider");
		}

		$this->provider = new $provider();
	}

	public function isEnabled(): bool
	{
		return $this->provider->isEnabled();
	}
}
