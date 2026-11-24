<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\License as MainLicense;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Integration\Crm\DealReader;
use Bitrix\Mobile\Internal\Integration\Intranet\PortalRoleReader;
use Psr\Container\ContainerInterface;

final class ServiceFactory
{
	private const CREATOR_SETTINGS_SERVICE = 'bitrix24.portal.settings.creator';
	private const CRM_CONTAINER_SERVICE = 'crm.service.container';

	private readonly ContainerInterface $services;

	public function __construct(?ContainerInterface $services = null)
	{
		$this->services = $services ?? ServiceLocator::getInstance();
	}

	public function createLicense(): License
	{
		return new License(services: $this);
	}

	public function createPortalRoleReader(): PortalRoleReader
	{
		return new PortalRoleReader(services: $this);
	}

	public function createDealReader(): DealReader
	{
		return new DealReader(services: $this);
	}

	public function mainLicense(): MainLicense
	{
		return Application::getInstance()->getLicense();
	}

	public function creatorSettings(): ?object
	{
		return $this->services->has(self::CREATOR_SETTINGS_SERVICE)
			? $this->services->get(self::CREATOR_SETTINGS_SERVICE)
			: null;
	}

	public function crmContainer(): Container
	{
		return $this->services->get(self::CRM_CONTAINER_SERVICE);
	}
}
