<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Driver;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\File;

final class Configuration
{
	public const DEFAULT_MAX_FILESIZE = 104857600;

	/** @var array|null override values from bitrix/php_interface/disk-vibeoffice.php */
	private ?array $localValues = null;

	public function __construct()
	{
		$this->loadLocalValues();
	}

	public function isEnabledOnPortal(): bool
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled', 'N') === 'Y';
	}

	/**
	 * Turns the portal flag off so that new document opens no longer route to vibeoffice.
	 *
	 * This is the first, mandatory step of the rollback drain ({@see \Bitrix\Disk\Document\Vibeoffice\DrainService},
	 * ALG-DRAIN): once cleared, {@see VibeofficeHandler::isEnabled()} returns false and the
	 * dispatch intercept stops forwarding to this handler, while drain still finishes the
	 * already-open sessions.
	 */
	public function disableOnPortal(): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled', 'N');
	}

	/**
	 * Base URL of the vibeoffice editing platform.
	 */
	public function getServer(): ?string
	{
		return $this->getValue('server', 'disk_vibeoffice_server');
	}

	/**
	 * Key identifier (X-VO-Key header value, e.g. vok_...).
	 */
	public function getKeyId(): ?string
	{
		return $this->getValue('key_id', 'disk_vibeoffice_key_id');
	}

	/**
	 * Secret used to sign outgoing protocol-client requests.
	 * Backend-only: never returned to the browser / document service.
	 */
	public function getApiSecret(): ?string
	{
		return $this->getValue('api_secret', 'disk_vibeoffice_api_secret');
	}

	/**
	 * Secret used to verify incoming webhooks.
	 */
	public function getWebhookSecret(): ?string
	{
		return $this->getValue('webhook_secret', 'disk_vibeoffice_webhook_secret');
	}

	/**
	 * Workgroup (project) ids the vibeoffice engine is narrowed to.
	 *
	 * The php_interface override (`enabled_group_ids`, an int[]) wins over the module option
	 * `disk_vibeoffice_enabled_groups` (a CSV string). Empty / non-numeric / non-positive entries
	 * are dropped and the result is a de-duplicated int[]. An empty array means no narrowing — the
	 * feature stays portal-wide (current behaviour).
	 *
	 * @return int[]
	 */
	public function getEnabledGroupIds(): array
	{
		$local = $this->getLocalValues('enabled_group_ids');
		if (is_array($local))
		{
			return $this->normalizeGroupIds($local);
		}

		$csv = (string)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled_groups', '');

		return $this->normalizeGroupIds(explode(',', $csv));
	}

	/**
	 * @param array $values
	 * @return int[]
	 */
	private function normalizeGroupIds(array $values): array
	{
		$ids = [];
		foreach ($values as $value)
		{
			if (is_string($value))
			{
				$value = trim($value);
			}
			if (!is_numeric($value))
			{
				continue;
			}
			$id = (int)$value;
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	public function getMaxFileSize(): int
	{
		$value = $this->getValue('max_filesize', 'disk_vibeoffice_max_filesize');

		if ($value === null || $value === '')
		{
			return self::DEFAULT_MAX_FILESIZE;
		}

		return (int)$value;
	}

	/**
	 * Resolves a value, giving the php_interface override priority over the module option.
	 */
	private function getValue(string $key, string $optionName): ?string
	{
		$local = $this->getLocalValues($key);
		if ($local !== null)
		{
			return (string)$local;
		}

		return Option::get(Driver::INTERNAL_MODULE_ID, $optionName, null);
	}

	private function getLocalValues(string $key): mixed
	{
		return $this->localValues[$key] ?? null;
	}

	private function loadLocalValues(): void
	{
		$path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/disk-vibeoffice.php';
		if (File::isFileExists($path))
		{
			$localValues = require($path);
			if (is_array($localValues))
			{
				$this->localValues = $localValues;
			}
		}
	}
}
