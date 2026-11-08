<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Mcp\McpToolMetadata;

class MethodDescription implements \Serializable
{
	private const RESOURCE_DTO_FQCN_IN_QUERY_PARAMS = true;

	public function __construct(
		public readonly string $module,
		public readonly ?string $controllerFqcn,
		public readonly string $method,
		public readonly ?string $dtoFqcn,
		public readonly array $scopes,
		public readonly string $actionUri,
		public readonly LocalizableMessage|string|null $title = null,
		public readonly LocalizableMessage|string|null $description = null,
		public readonly bool $isEnabled = true,
		public readonly ?array $queryParams = null,
		public readonly bool $isHidden = false,
		public readonly ?McpToolMetadata $mcpTool = null,
		public readonly bool $isDeprecated = false,
		public readonly ?string $accessProvider = null,
		/**
		 * Resource the generated field methods belong to. Their own dtoFqcn is the system DTO of
		 * the response, so this is the only property that names the guarded resource.
		 */
		public readonly ?string $resourceDtoFqcn = null,
	) {
	}

	/**
	 * Whether the route is excluded from the MCP export: ControllerDataManager drops its
	 * mcpTool, so a null metadata on such a route means "removed", not "never declared".
	 */
	public function isMcpExportSuppressed(): bool
	{
		return $this->isHidden || $this->isDeprecated || !$this->isEnabled;
	}

	public function serialize(): ?string
	{
		return serialize($this->__serialize());
	}

	public function unserialize(string $data): void
	{
		$this->__unserialize(unserialize($data, ['allowed_classes' => false]));
	}

	public function __serialize(): array
	{
		$data = [
			'module' => $this->module,
			'controllerFqcn' => $this->controllerFqcn,
			'method' => $this->method,
			'dtoFqcn' => $this->dtoFqcn,
			'scopes' => $this->scopes,
			'actionUri' => $this->actionUri,
			'title' => $this->title,
			'description' => $this->description,
			'isEnabled' => $this->isEnabled,
			'queryParams' => $this->queryParams,
			'isHidden' => $this->isHidden,
			'mcpTool' => $this->mcpTool?->toArray(),
			'isDeprecated' => $this->isDeprecated,
		];

		// most of the schema has neither of the two, and __unserialize reads both as optional
		if ($this->accessProvider !== null)
		{
			$data['accessProvider'] = $this->accessProvider;
		}

		if ($this->resourceDtoFqcn !== null)
		{
			$data['resourceDtoFqcn'] = $this->isResourceDtoFqcnTakenFromQueryParams()
				? self::RESOURCE_DTO_FQCN_IN_QUERY_PARAMS
				: $this->resourceDtoFqcn
			;
		}

		return $data;
	}

	public function __unserialize(array $data): void
	{
		$this->module = $data['module'];
		$this->controllerFqcn = $data['controllerFqcn'];
		$this->method = $data['method'];
		$this->dtoFqcn = $data['dtoFqcn'];
		$this->scopes = $data['scopes'];
		$this->actionUri = $data['actionUri'];
		$this->title = $data['title'];
		$this->description = $data['description'];
		$this->isEnabled = $data['isEnabled'];
		$this->queryParams = $data['queryParams'];
		$this->isHidden = $data['isHidden'] ?? false;
		$this->mcpTool = McpToolMetadata::fromArray($data['mcpTool'] ?? null);
		$this->isDeprecated = $data['isDeprecated'] ?? false;
		$this->accessProvider = $data['accessProvider'] ?? null;

		$resourceDtoFqcn = $data['resourceDtoFqcn'] ?? null;
		$this->resourceDtoFqcn = $resourceDtoFqcn === self::RESOURCE_DTO_FQCN_IN_QUERY_PARAMS
			? ($data['queryParams']['dtoClass'] ?? null)
			: $resourceDtoFqcn
		;
	}

	/**
	 * field.list and field.get already carry the resource fqcn as the dtoClass of the route, so the
	 * marker replaces the second copy of the very same string in the schema cache.
	 */
	private function isResourceDtoFqcnTakenFromQueryParams(): bool
	{
		return $this->resourceDtoFqcn === ($this->queryParams['dtoClass'] ?? null);
	}
}
