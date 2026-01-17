<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Type\DateTime;

class ModuleConfig
{
	public function __construct(
		public readonly string $id,
		public readonly string $version,
		public readonly string $defaultNamespace,
		public readonly array $namespaces,
		public readonly array $routes,
		public readonly ?string $schemaProviderClass,
		public readonly array $documentation,
		public readonly ?DateTime $modificationDateTime
	) {
	}
}
