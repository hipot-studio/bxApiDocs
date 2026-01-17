<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Rest\V3\Attribute\Enabled;

class ControllerData implements Arrayable
{
	public readonly \ReflectionClass $controller;
	public readonly ?\ReflectionClass $dto;
	/**
	 * @var MethodDescription[]
	 */
	private array $methodDescriptions = [];
	private bool $enabled;

	public function __construct(
		public readonly string $module,
		string $controller,
		?string $dto = null,
		public readonly ?string $namespace = null,
		array $methods = [],
		?bool $isEnabled = null,
	) {
		$this->controller = new \ReflectionClass($controller);
		$this->dto = $dto !== null ? new \ReflectionClass($dto) : null;
		if ($isEnabled === null)
		{
			$isEnabledAttribute = $this->controller->getAttributes(Enabled::class);
			if (!empty($isEnabledAttribute))
			{
				/** @var Enabled $isEnabledAttribute */
				$enabledAttribute = $isEnabledAttribute[0]->newInstance();
				$this->enabled = $enabledAttribute->isEnabled();
			}
			else
			{
				$this->enabled = true;
			}
		}
		else
		{
			$this->enabled = $isEnabled;
		}
		foreach ($methods as $methodDescription)
		{
			if (!$methodDescription instanceof MethodDescription)
			{
				throw new \InvalidArgumentException('All items in $methods must be instances of MethodDescription or arrays.');
			}
			$this->methodDescriptions[$methodDescription->actionUri] = $methodDescription;
		}
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function getUri(): string
	{
		$namespace = strtolower(trim($this->namespace, '\\'));

		$controllerName = strtolower($this->controller->getName());
		$controllerUri = str_replace('\\', '.', trim(str_replace($namespace,'', $controllerName), '\\'));

		return $this->module . '.' . $controllerUri;
	}

	public function getMethodUri(string $method): string
	{
		return $this->getUri() . '.' . strtolower($method);
	}

	public function getMethods(): array
	{
		return $this->methodDescriptions;
	}

	public function addMethod(MethodDescription $methodDescription): self
	{
		$this->methodDescriptions[$methodDescription->actionUri] = $methodDescription;

		return $this;
	}

	public static function fromArray(array $data): self
	{
		if (empty($data['module']) || !is_string($data['module']))
		{
			throw new SystemException('Parameter "module" is required and must be a string.');
		}
		if (empty($data['controller']) || !is_string($data['controller']))
		{
			throw new SystemException('Parameter "controller" is required and must be a string.');
		}
		if (isset($data['dto']) && !is_null($data['dto']) && !is_string($data['dto']))
		{
			throw new SystemException('Parameter "dto" must be a string or null.');
		}
		if (isset($data['namespace']) && !is_null($data['namespace']) && !is_string($data['namespace']))
		{
			throw new SystemException('Parameter "namespace" must be a string or null.');
		}
		if (isset($data['methods']) && !is_array($data['methods']))
		{
			throw new SystemException('Parameter "methods" must be an array.');
		}

		return new self(
			$data['module'],
			$data['controller'],
			$data['dto'] ?? null,
			$data['namespace'] ?? null,
			$data['methods'] ?? [],
		);
	}

	public function toArray(): array
	{
		$data = [
			'module' => $this->module,
			'controller' => $this->controller->getName(),
			'dto' => $this->dto?->getName(),
			'namespace' => $this->namespace,
		];

		foreach ($this->methodDescriptions as $methodDescription)
		{
			$data['methods'][$methodDescription->actionUri] = $methodDescription;
		}

		return $data;
	}
}
