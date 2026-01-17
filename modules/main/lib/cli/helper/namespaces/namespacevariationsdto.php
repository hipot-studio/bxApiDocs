<?php

namespace Bitrix\Main\Cli\Helper\Namespaces;

interface NamespaceVariationsDto
{
	public function setCustomNamespace(string $customNamespace): void;

	public function setPrefixForStandardNamespace(string $prefix): void;

	public function setPrefixForContext(string $prefix): void;

	public function getNamespace(string $standardNamespace): string;
}
