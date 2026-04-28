<?php

namespace Bitrix\Disk\Document\OnlyOffice;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Service\CustomServerConfigAdapter;
use Bitrix\Disk\Public\Provider\CustomServerAvailabilityProvider;
use Bitrix\Disk\Public\Provider\CustomServerProvider;
use Bitrix\Main;
use Bitrix\Disk\Driver;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\File;
use Bitrix\Main\ModuleManager;

final class Configuration
{
	public const DEFAULT_MAX_FILESIZE = 104857600;
	public const MODE_CLOUD = 2;
	public const MODE_LOCAL = 3;

	protected ?string $originalServer = null;
	protected ?string $customServer = null;
	protected ?string $originalSecretKey = null;
	protected ?string $customSecretKey = null;
	protected ?int $originalMaxFileSize = null;
	protected ?int $customMaxFileSize = null;
	/** @var array|null */
	protected $localValues;
	protected CustomServerAvailabilityProvider $customServerAvailabilityProvider;
	protected CustomServerProvider $customServerProvider;

	public function __construct()
	{
		$this->loadLocalValues();

		$this->customServerAvailabilityProvider =
			Main\DI\ServiceLocator
				::getInstance()
				->get(CustomServerAvailabilityProvider::class)
		;

		$this->customServerProvider = Main\DI\ServiceLocator::getInstance()->get(CustomServerProvider::class);
	}

	protected function loadLocalValues(): void
	{
		$path = $_SERVER["DOCUMENT_ROOT"]."/bitrix/php_interface/disk-documents.php";
		if (File::isFileExists($path))
		{
			$localValues = require($path);
			if (is_array($localValues))
			{
				$this->localValues = $localValues;
			}
		}
	}

	public function getInstallationMode(): ?int
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_install_mode', null);
	}

	public function getB24DocumentsServerListEndpoint(): ?string
	{
		$b24documentsPrimary = Main\Config\Configuration::getInstance()->get('b24documents');
		$b24documents = Main\Config\Configuration::getInstance('disk')->get('b24documents');

		return $b24documentsPrimary['serverListEndpoint'] ?? $b24documents['serverListEndpoint'];
	}

	public function setInstallationMode(int $mode): void
	{
		if ($mode === self::MODE_LOCAL && !ModuleManager::isModuleInstalled('bitrix24'))
		{
			throw new ArgumentException('Installation mode should be MODE_CLOUD');
		}

		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_install_mode', $mode);
	}

	/**
	 * @see \Bitrix\DocumentProxy\Controller\Registration::registerClientAction()
	 * @return string|null
	 */
	public function getTempSecretForDomainVerification(): ?string
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_temp_secret', null);
	}

	public function resetTempSecretForDomainVerification(): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_temp_secret', null);
	}

	public function storeTempSecretForDomainVerification(string $value): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_temp_secret', $value);
	}

	/**
	 * @param array{clientId: string, secretKey: string, serverHost: string} $data
	 * @return void
	 */
	public function storeCloudRegistration(array $data): void
	{
		if (!isset($data['clientId'], $data['secretKey'], $data['serverHost']))
		{
			return;
		}

		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_b24_clientId', $data['clientId']);
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_b24_secretKey', $data['secretKey']);
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_b24_serverHost', $data['serverHost']);
	}

	public function resetCloudRegistration(): void
	{
		Option::delete('disk', [
			'name' => 'disk_onlyoffice_b24_clientId',
		]);
		Option::delete('disk', [
			'name' => 'disk_onlyoffice_b24_secretKey',
		]);
		Option::delete('disk', [
			'name' => 'disk_onlyoffice_b24_serverHost',
		]);
	}

	/**
	 * @return null|array{clientId: string, secretKey: string, serverHost: string}
	 */
	public function getCloudRegistrationData(): ?array
	{
		$data = array_filter([
			'clientId' => Option::get(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_b24_clientId'),
			'secretKey' => Option::get(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_b24_secretKey'),
			'serverHost' => Option::get(Driver::INTERNAL_MODULE_ID, 'disk_onlyoffice_b24_serverHost'),
		]);

		if (count($data) === 3)
		{
			return $data;
		}

		return null;
	}

	public function getServer(bool $original = false): ?string
	{
		if (!$original)
		{
			if (!is_string($this->customServer))
			{
				$this->customServer = $this->getValue(
					key: 'server',
					optionName: 'disk_onlyoffice_server',
				);
			}

			if (is_string($this->customServer))
			{
				return $this->customServer;
			}
		}

		if (!is_string($this->originalServer))
		{
			$this->originalServer = $this->getValue(
				key: 'server',
				optionName: 'disk_onlyoffice_server',
				original: true,
			);
		}

		return $this->originalServer;
	}

	public function getDomain(): ?string
	{
		$server = $this->getServer();

		if (!is_string($server))
		{
			return null;
		}

		$server = preg_replace('/^https?:\/\//', '', $server);

		return rtrim($server, '/');
	}

	public function getSecretKey(bool $original = false): ?string
	{
		if (!$original)
		{
			if (!is_string($this->customSecretKey))
			{
				$this->customSecretKey = $this->getValue(
					key: 'secret_key',
					optionName: 'disk_onlyoffice_secret_key',
				);
			}

			if (is_string($this->customSecretKey))
			{
				return $this->customSecretKey;
			}
		}

		if (!is_string($this->originalSecretKey))
		{
			$cloudData = $this->getCloudRegistrationData();
			if (isset($cloudData['secretKey']) && $cloudData['secretKey'])
			{
				$this->originalSecretKey = $cloudData['secretKey'];
			}
			else
			{
				$this->originalSecretKey = $this->getValue(
					key: 'secret_key',
					optionName: 'disk_onlyoffice_secret_key',
					original: true,
				);
			}
		}

		return $this->originalSecretKey;
	}

	public function getMaxFileSize(bool $original = false): ?int
	{
		if (!$original)
		{
			if (!is_int($this->customMaxFileSize))
			{
				$this->customMaxFileSize = $this->getValue(
					key: 'max_filesize',
					optionName: 'disk_onlyoffice_max_filesize',
				);
			}

			if (is_int($this->customMaxFileSize))
			{
				return $this->customMaxFileSize;
			}
		}

		if (!is_int($this->originalMaxFileSize))
		{
			$value = $this->getValue(
				key: 'max_filesize',
				optionName: 'disk_onlyoffice_max_filesize',
				original: true,
			);

			$this->originalMaxFileSize = ($value === null || $value === '') ? self::DEFAULT_MAX_FILESIZE : (int)$value;
		}

		return $this->originalMaxFileSize;
	}

	protected function getValue($key, string $optionName, bool $original = false): mixed
	{
		if ($original)
		{
			$value = $this->getLocalValues($key);
			if ($value === null)
			{
				return Option::get(Driver::INTERNAL_MODULE_ID, $optionName, null);
			}

			return $value;
		}

		$customConfigType = \Bitrix\Disk\Configuration::getDefaultViewerCustomConfigType();

		if (!$customConfigType instanceof CustomServerTypes)
		{
			return null;
		}

		$customServer = $this->customServerProvider->getFirstByType($customConfigType);

		if (!$customServer instanceof CustomServerInterface)
		{
			return null;
		}

		if (!$this->customServerAvailabilityProvider->isAvailableCustomServerForUse($customServer))
		{
			return null;
		}

		return CustomServerConfigAdapter::getValue($customServer, $optionName);
	}

	public function getLocalValues($key)
	{
		return $this->localValues[$key] ?? null;
	}
}