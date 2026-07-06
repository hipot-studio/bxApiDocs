<?php

namespace Bitrix\Baas\UseCase\External\Entity;

use \Bitrix\Baas;
use Bitrix\Main\Web\Json;

class Client
{
	protected ?string $secret;
	protected Baas\Config\Client $configs;

	public function getConfigs(): Baas\Config\Client
	{
		if (!isset($this->configs))
		{
			$this->configs = new Baas\Config\Client();
		}

		return $this->configs;
	}

	public function getHost(): string
	{
		return $this->getConfigs()->getHost();
	}

	public function setSynCode(string $code): static
	{
		$this->secret = $code;
		$this->getConfigs()->setSynCode($code);

		return $this;
	}

	public function getSynCode(): ?string
	{
		if (!isset($this->secret))
		{
			$this->secret = $this->getConfigs()->getSynCode();
		}

		return $this->secret;
	}

	public function setRegistrationData(string $clientId, string $clientKey): static
	{
		$this->getConfigs()->setRegistrationData($clientId, $clientKey);

		return $this;
	}

	public function getRegistrationData(): array
	{
		return $this->getConfigs()->getRegistrationData();
	}

	public function isTurnedOn(): bool
	{
		return $this->getConfigs()->isTurnedOn();
	}

	public function turnOff(): static
	{
		\CEventLog::Log(
			\CEventLog::SEVERITY_INFO,
			'BAAS_TURN_OFF',
			'baas',
			'',
			Json::encode([
				'time' => date("Y-m-d H:i:s"),
			]),
		);

		$this->getConfigs()->turnOff();

		return $this;
	}

	public function turnOn(): static
	{
		$this->getConfigs()->turnOn();

		return $this;
	}
}
