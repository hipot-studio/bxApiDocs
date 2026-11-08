<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\DateTime;

final readonly class ModuleConfig implements Arrayable
{
	public function __construct(
		public string $id,
		public string $version,
		public string $defaultNamespace,
		public array $namespaces,
		public array $routes,
		public ?string $schemaProviderClass,
		public array $documentation,
		public ?DateTime $modificationDateTime,
		public ?array $eventProviders,
	) {
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'version' => $this->version,
			'defaultNamespace' => $this->defaultNamespace,
			'namespaces' => $this->namespaces,
			'routes' => $this->routes,
			'schemaProviderClass' => $this->schemaProviderClass,
			'documentation' => $this->documentation,
			'modificationDateTime' => $this->modificationDateTime?->format(DATE_ATOM),
			'eventProviders' => $this->eventProviders,
		];
	}
}
