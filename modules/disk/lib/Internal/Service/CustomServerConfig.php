<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use RuntimeException;

/**
 * Configuration for custom server.
 */
class CustomServerConfig
{
	public const CLASS_NAME_KEY = 'className';
	public const TYPE_KEY = 'type';
	public const IS_ENABLED_KEY = 'isEnabled';
	public const TITLE_KEY = 'title';
	public const ADMIN_TEMPLATES_KEY = 'adminTemplates';
	public const SUPPORTED_VERSIONS_KEY = 'supportedVersions';
	public const MAX_FILE_SIZE_KEY = 'maxFileSize';
	public const AVAILABLE_REGIONS_KEY = 'availableRegions';
	public const UNAVAILABLE_REGIONS_KEY = 'unavailable';

	protected ?CustomServerTypes $type = null;

	/**
	 * @return string|null
	 */
	public function getClassName(): ?string
	{
		return $this->getFromUnderlyingConfig(static::CLASS_NAME_KEY);
	}

	/**
	 * @param CustomServerTypes $type
	 * @return void
	 */
	public function setType(CustomServerTypes $type): void
	{
		$this->type = $type;
	}

	/**
	 * @return CustomServerTypes|null
	 */
	public function getType(): ?CustomServerTypes
	{
		return $this->type;
	}

	/**
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->getFromUnderlyingConfig(static::IS_ENABLED_KEY) ?? false;
	}

	/**
	 * @return string|null
	 */
	public function getTitle(): ?string
	{
		return $this->getFromUnderlyingConfig(static::TITLE_KEY);
	}

	/**
	 * @param string $name
	 * @return string|null
	 */
	public function getAdminTemplatePath(string $name): ?string
	{
		return $this->getFromUnderlyingConfig(static::ADMIN_TEMPLATES_KEY)[$name] ?? null;
	}

	/**
	 * @return array|null
	 */
	public function getSupportedVersions(): ?array
	{
		return $this->getFromUnderlyingConfig(static::SUPPORTED_VERSIONS_KEY);
	}

	/**
	 * @return int|null
	 */
	public function getMaxFileSize(): ?int
	{
		return $this->getFromUnderlyingConfig(static::MAX_FILE_SIZE_KEY);
	}

	/**
	 * @return array|null
	 */
	public function getAvailableRegions(): ?array
	{
		return $this->getFromUnderlyingConfig(static::AVAILABLE_REGIONS_KEY);
	}

	/**
	 * @return array|null
	 */
	public function getUnavailableRegions(): ?array
	{
		return $this->getFromUnderlyingConfig(static::UNAVAILABLE_REGIONS_KEY);
	}

	/**
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	protected function getFromUnderlyingConfig(string $key, mixed $default = null): mixed
	{
		if (!$this->type instanceof CustomServerTypes)
		{
			throw new RuntimeException('Type must be instance of CustomServerTypes');
		}

		return Configuration::getCustomServers()[$this->type->value][$key] ?? $default;
	}
}
