<?php

namespace Bitrix\Main\Config;

use Bitrix\Main;
use Bitrix\Main\Loader;

final class Configuration implements \ArrayAccess, \Iterator, \Countable
{
	const CONFIGURATION_FILE = '.settings.php';
	const CONFIGURATION_FILE_EXTRA = '.settings_extra.php';
	/** @deprecated */
	const CONFIGURATION_FILE_PATH = '/bitrix/' . self::CONFIGURATION_FILE;

	/**
	 * @var Configuration[]
	 */
	private static array $instances = [];
	private ?string $moduleId;
	private ?string $path;
	private ?array $storedData = null;
	private array $data = [];
	private bool $isLoaded = false;

	public static function getValue($name)
	{
		$configuration = Configuration::getInstance();
		return $configuration->get($name);
	}

	public static function setValue($name, $value): void
	{
		$configuration = Configuration::getInstance();
		$configuration->add($name, $value);
		$configuration->saveConfiguration();
	}

	private function __construct(?string $moduleId = null, ?string $path = null)
	{
		if ($moduleId !== null)
		{
			$moduleId = preg_replace("/[^a-zA-Z0-9_.]+/i", "", trim($moduleId));
		}
		$this->moduleId = $moduleId;
		$this->path = $path;
	}

	public static function getInstance(?string $moduleId = null): self
	{
		$index = $moduleId . '|';

		if (!isset(self::$instances[$index]))
		{
			self::$instances[$index] = new self($moduleId);
		}

		return self::$instances[$index];
	}

	public static function getInstanceByPath(string $path): self
	{
		$index = '|' . $path;

		if (!isset(self::$instances[$index]))
		{
			self::$instances[$index] = new self(null, $path);
		}

		return self::$instances[$index];
	}

	private static function getPathConfigForModule(string $moduleId): ?string
	{
		if (!Main\ModuleManager::isModuleInstalled($moduleId))
		{
			return null;
		}

		$moduleConfigPath = Loader::getLocal("modules/{$moduleId}/" . self::CONFIGURATION_FILE);
		if ($moduleConfigPath === false)
		{
			return null;
		}

		return $moduleConfigPath;
	}

	private function loadConfiguration(): void
	{
		if ($this->moduleId || $this->path)
		{
			$path = $this->path ?? self::getPathConfigForModule($this->moduleId);
			if ($path !== null && file_exists($path))
			{
				$dataTmp = include $path;
				if (is_array($dataTmp))
				{
					$this->data = $dataTmp;
				}
			}
		}
		else
		{
			if (($path = Loader::getLocal(self::CONFIGURATION_FILE)) !== false)
			{
				$dataTmp = include $path;
				if (is_array($dataTmp))
				{
					$this->data = $dataTmp;
				}
			}

			if (($pathExtra = Loader::getLocal(self::CONFIGURATION_FILE_EXTRA)) !== false)
			{
				$dataTmp = include $pathExtra;
				if (is_array($dataTmp) && !empty($dataTmp))
				{
					$this->storedData = $this->data;
					foreach ($dataTmp as $k => $v)
					{
						$this->data[$k] = $v;
					}
				}
			}
		}

		$this->isLoaded = true;
	}

	public function saveConfiguration(): void
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		if ($this->moduleId)
		{
			throw new Main\InvalidOperationException('There is no support to rewrite .settings.php in module');
		}
		else
		{
			$path = $this->path ?? Loader::getLocal(self::CONFIGURATION_FILE);
		}

		$data = ($this->storedData !== null) ? $this->storedData : $this->data;
		$data = var_export($data, true);

		$dir = dirname($path);
		if (!is_dir($dir))
		{
			mkdir($dir, defined('BX_DIR_PERMISSIONS') ? BX_DIR_PERMISSIONS : 0755, true);
		}
		if (file_exists($path) && !is_writable($path))
		{
			@chmod($path, defined('BX_FILE_PERMISSIONS') ? BX_FILE_PERMISSIONS : 0644);
		}
		file_put_contents($path, "<" . "?php\nreturn " . $data . ";\n");
	}

	public function add($name, $value): self
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		if (!isset($this->data[$name]) || !$this->data[$name]["readonly"])
		{
			$this->data[$name] = ["value" => $value, "readonly" => false];
		}
		if (($this->storedData !== null) && (!isset($this->storedData[$name]) || !$this->storedData[$name]["readonly"]))
		{
			$this->storedData[$name] = ["value" => $value, "readonly" => false];
		}

		return $this;
	}

	/**
	 * Changes readonly params.
	 * Warning! Developer must use this method very carefully!
	 * You must use this method only if you know what you do!
	 * @param string $name
	 * @param array $value
	 * @return Configuration
	 */
	public function addReadonly($name, $value): self
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		$this->data[$name] = ["value" => $value, "readonly" => true];
		if ($this->storedData !== null)
		{
			$this->storedData[$name] = ["value" => $value, "readonly" => true];
		}

		return $this;
	}

	public function delete($name): self
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		if (isset($this->data[$name]) && !$this->data[$name]["readonly"])
		{
			unset($this->data[$name]);
		}
		if (($this->storedData !== null) && isset($this->storedData[$name]) && !$this->storedData[$name]["readonly"])
		{
			unset($this->storedData[$name]);
		}

		return $this;
	}

	public function get($name)
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		if (isset($this->data[$name]['value']))
		{
			return $this->data[$name]['value'];
		}

		return null;
	}

	public function offsetExists($offset): bool
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		return isset($this->data[$offset]);
	}

	public function offsetGet($offset): mixed
	{
		return $this->get($offset);
	}

	public function offsetSet($offset, $value): void
	{
		$this->add($offset, $value);
	}

	public function offsetUnset($offset): void
	{
		$this->delete($offset);
	}

	public function current(): mixed
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		$c = current($this->data);

		return $c === false ? false : $c["value"];
	}

	public function next(): void
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		next($this->data);
	}

	public function key(): mixed
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		return key($this->data);
	}

	public function valid(): bool
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		$key = $this->key();
		return isset($this->data[$key]);
	}

	public function rewind(): void
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		reset($this->data);
	}

	public function count(): int
	{
		if (!$this->isLoaded)
		{
			$this->loadConfiguration();
		}

		return count($this->data);
	}

	public static function wnc(): void
	{
		Migrator::wnc();
	}
}
