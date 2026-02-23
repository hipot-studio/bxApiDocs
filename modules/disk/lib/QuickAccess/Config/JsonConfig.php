<?php
declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\Config;

use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\FileNotFoundException;
use JsonException;

class JsonConfig implements ConfigInterface
{
	private const PATH_TO_CONFIG = '/bitrix/quick-access-config.php';
	private File $file;
	private ?array $config = null;

	public function __construct()
	{
		$fullPath = Application::getDocumentRoot() . self::PATH_TO_CONFIG;
		$this->file = new File($fullPath);
	}

	public function isset(): bool
	{
		return $this->file->isExists()
			&& $this->getKey() !== null
			&& !empty($this->getTokenStorage());
	}

	public function getKey(): ?string
	{
		return $this->getConfig()[self::CONFIG_KEY] ?? null;
	}

	public function getTokenStorage(): array
	{
		return $this->getConfig()[self::CONFIG_STORAGE] ?? [];
	}

	private function getConfig(): array
	{
		if ($this->config === null)
		{
			$this->config = $this->getFromFile();
		}

		return $this->config;
	}

	private function getFromFile(): array
	{
		try
		{
			$fileContent = $this->file->getContents();

			return json_decode($fileContent, true, 3, JSON_THROW_ON_ERROR);
		}
		catch (JsonException|FileNotFoundException)
		{
			return [];
		}
	}
}
