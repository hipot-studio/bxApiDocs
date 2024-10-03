<?php

namespace Bitrix\Intranet\Service;

use Bitrix\Intranet\Contract\Repository\DepartmentRepository;
use Bitrix\Intranet\Repository\InvitationRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class ServiceContainer implements ContainerInterface
{
	private static ServiceContainer $instance;
	private ServiceLocator $serviceLocator;
	private string $prefix;

	private function __construct()
	{
		$this->serviceLocator = ServiceLocator::getInstance();
		$this->prefix = 'intranet.';
	}

	private function __clone()
	{}

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function has(string $id): bool
	{
		return $this->serviceLocator->has($this->prefix.$id);
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws ObjectNotFoundException
	 */
	public function get(string $id): mixed
	{
		return $this->serviceLocator->get($this->prefix.$id);
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws LoaderException
	 * @throws ObjectNotFoundException
	 */
	public function departmentRepository(): DepartmentRepository
	{
		return $this->get('repository.department');
	}

	public function invitationRepository(): InvitationRepository
	{
		return $this->get('repository.invitation');
	}
}