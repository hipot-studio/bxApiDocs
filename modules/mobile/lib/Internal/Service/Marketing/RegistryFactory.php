<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

use Bitrix\Main\DI\ServiceLocator;
use Psr\Container\ContainerInterface;

final class RegistryFactory
{
	private readonly ContainerInterface $services;

	public function __construct(?ContainerInterface $services = null)
	{
		$this->services = $services ?? ServiceLocator::getInstance();
	}

	public function fromConfiguration(?array $section = null, ?SelectionLog $log = null): Registry
	{
		return Registry::fromConfiguration($this->services, $section, $log);
	}
}
