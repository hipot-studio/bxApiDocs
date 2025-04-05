<?php

declare(strict_types=1);

namespace Bitrix\Baas\Integration\Main;

use Bitrix\Main;
use Bitrix\Baas;

class License implements Baas\Contract\License
{
	protected Baas\Config\Client $config;
	protected ?Main\License $license = null;
	protected bool $active = false;

	public function __construct(
		?Baas\Config\Client $config = null
	)
	{
		$this->config = $config ?? new Baas\Config\Client();

		$this->license = new Main\License();
		$this->active = $this->license->getExpireDate() >= new Main\Type\DateTime();
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function isActive(): bool
	{
		return $this->active;
	}

	public function isBaasAvailable(): bool
	{
		return $this->config->isRegistered() && $this->config->isTurnedOn();
	}

	public function isSellableToAll(): bool
	{
		return true;
	}
}
