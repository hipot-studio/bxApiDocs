<?php

namespace Bitrix\Main\Data\Configurator;

use Bitrix\Main\Application;

abstract class ConnectionConfigurator
{
	protected array $config = [];
	protected array $servers = [];

	public function __construct(array $config)
	{
		$this->config = $config;
		$this->addServers($this->getConfig());
	}

	public function getConfig(): array
	{
		return $this->config;
	}

	abstract protected function addServers(array $config): void;

	protected function log(): void
	{
		$error = error_get_last();
		if (isset($error['type']) && $error['type'] === E_WARNING)
		{
			$exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
			$application = Application::getInstance();
			$exceptionHandler = $application->getExceptionHandler();
			$exceptionHandler->writeToLog($exception);
		}
	}
}
