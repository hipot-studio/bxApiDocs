<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\CustomServers;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Interface\CustomServerWithConfigInterface;
use Bitrix\Disk\Internal\Interface\CustomServerWithDataInterface;
use Bitrix\Disk\Internal\Service\CustomServerConfig;
use Bitrix\Main\Error;

abstract class AbstractCustomServer implements
	CustomServerInterface,
	CustomServerWithConfigInterface,
	CustomServerWithDataInterface
{
	public const DATA_ID_KEY = 'id';
	public const DATA_NAME_KEY = 'name';

	protected ?CustomServerConfig $config = null;
	protected ?array $data = null;

	/**
	 * {@inheritDoc}
	 */
	public function setConfig(?CustomServerConfig $config): void
	{
		$this->config = $config;
	}

	/**
	 * @return mixed
	 */
	public function getId(): mixed
	{
		return $this->data[static::DATA_ID_KEY] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getType(): ?CustomServerTypes
	{
		return $this->config?->getType();
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTitle(): ?string
	{
		return $this->config?->getTitle();
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAdminTemplatePath(string $name): ?string
	{
		return $this->config?->getAdminTemplatePath($name);
	}

	/**
	 * {@inheritDoc}
	 */
	public function setData(?array $data): void
	{
		$this->data = $data;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getData(): ?array
	{
		return $this->data;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getName(): ?string
	{
		return $this->data[static::DATA_NAME_KEY] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isEnabled(): bool
	{
		return $this->config?->isEnabled() ?? false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepareDataForConnect(): ?Error
	{
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool
	{
		return !empty($this->data);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAvailableRegions(): ?array
	{
		return $this->config?->getAvailableRegions();
	}

	/**
	 * {@inheritDoc}
	 */
	public function getUnavailableRegions(): ?array
	{
		return $this->config?->getUnavailableRegions();
	}
}
