<?php

namespace Bitrix\Main\Cli\Helper\PathGenerator;

use Bitrix\Main\Cli\Helper\PathGenerator;
use Bitrix\Main\Loader;

class LocalGenerator implements PathGenerator
{
	public function __construct(
		protected bool $isCamelCase = true,
	)
	{}

	public function setCamelCase(bool $isCamelCase): void
	{
		$this->isCamelCase = $isCamelCase;
	}

	public function generatePathToClass(string $namespace, string $className): string
	{
		return
			Loader::getDocumentRoot()
			. '/'
			. $this->generatePathByNamespace($namespace)
			. '/'
			. $this->generateFileNameByClass($className)
		;
	}

	public function generatePathByNamespace(string $namespace): string
	{
		$parts = explode('\\', trim($namespace, '\\'));
		$moduleParts = array_slice($parts, 0, 2);
		$tailParts = array_slice($parts, 2);

		$moduleParts = array_map(
			static fn($part) => strtolower($part),
			$moduleParts
		);
		if (!$this->isCamelCase)
		{
			$tailParts = array_map(
				static fn($part) => strtolower($part),
				$tailParts
			);
		}

		if ($moduleParts[0] === 'bitrix')
		{
			$modulesFolder = 'bitrix/modules/';
			unset($moduleParts[0]);
		}
		else
		{
			$modulesFolder = 'local/modules/';
		}

		return
			$modulesFolder
			. join('.', $moduleParts)
			. '/lib/'
			. join(DIRECTORY_SEPARATOR, $tailParts)
		;
	}

	protected function generateFileNameByClass(string $className, string $ext = '.php'): string
	{
		if ($this->isCamelCase)
		{
			return $className . $ext;
		}

		return strtolower($className) . $ext;
	}
}
