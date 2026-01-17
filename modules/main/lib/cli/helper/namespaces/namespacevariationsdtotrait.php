<?php

namespace Bitrix\Main\Cli\Helper\Namespaces;

trait NamespaceVariationsDtoTrait
{
	private ?string $customNamespace;
	private ?string $prefixForContext;
	private ?string $prefixForStandardNamespace;

	public function setCustomNamespace(string $customNamespace): void
	{
		$this->customNamespace = $customNamespace;
	}

	public function setPrefixForStandardNamespace(string $prefix): void
	{
		$this->prefixForStandardNamespace = trim($prefix, '\\');
	}

	public function setPrefixForContext(string $prefix): void
	{
		$this->prefixForContext = trim($prefix, '\\');
	}

	public function getNamespace(string $standardNamespace): string
	{
		if (!empty($this->customNamespace))
		{
			return $this->customNamespace;
		}

		$standardNamespacePrepared = trim($standardNamespace, '\\');
		$resultNamespace = $standardNamespacePrepared;

		if (!empty($this->prefixForStandardNamespace))
		{
			$resultNamespace =
				$this->prefixForStandardNamespace
				. '\\'
				. $standardNamespacePrepared
			;
		}

		if (!empty($this->prefixForContext))
		{
			$resultNamespace .= '\\' . $this->prefixForContext;
		}

		return $resultNamespace;
	}
}
