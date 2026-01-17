<?php

namespace Bitrix\Main\Cli\Helper;

interface PathGenerator
{
	public function setCamelCase(bool $isCamelCase): void;

	public function generatePathToClass(string $namespace, string $className): string;

	public function generatePathByNamespace(string $namespace): string;
}
