<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Localization\LocalizableMessage;

class MethodDescription implements \Serializable
{
	public function __construct(
		public readonly string $module,
		public readonly ?string $controller,
		public readonly string $method,
		public readonly ?string $dtoClass,
		public readonly array $scopes,
		public readonly string $actionUri,
		public readonly LocalizableMessage|string|null $title = null,
		public readonly LocalizableMessage|string|null $description = null,
		public readonly bool $isEnabled = true,
		public readonly ?array $queryParams = null,
	) {
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
		return [
			'module' => $this->module,
			'controller' => $this->controller,
			'method' => $this->method,
			'dtoClass' => $this->dtoClass,
			'scopes' => $this->scopes,
			'actionUri' => $this->actionUri,
			'title' => $this->title,
			'description' => $this->description,
			'isEnabled' => $this->isEnabled,
			'queryParams' => $this->queryParams,
		];
	}

	public function __unserialize(array $data): void
	{
		$this->module = $data['module'];
		$this->controller = $data['controller'];
		$this->method = $data['method'];
		$this->dtoClass = $data['dtoClass'];
		$this->scopes = $data['scopes'];
		$this->actionUri = $data['actionUri'];
		$this->title = $data['title'];
		$this->description = $data['description'];
		$this->isEnabled = $data['isEnabled'];
		$this->queryParams = $data['queryParams'];
	}
}
