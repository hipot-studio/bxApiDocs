<?php
namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Contract\Arrayable;

final class ControllerData implements Arrayable
{
	/**
	 * @var array<string, MethodDescription>
	 */
	private array $methodDescriptions = [];

	public function __construct(
		public readonly string $module,
		public readonly string $controllerFqcn,
		public readonly ?string $dtoFqcn = null,
		public readonly ?string $namespace = null,
		public readonly bool $enabled = true,
		array $methods = [],
	)
	{
		foreach ($methods as $methodDescription)
		{
			if (!$methodDescription instanceof MethodDescription)
			{
				throw new \InvalidArgumentException('All items in $methods must be instances of MethodDescription.');
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
		$namespacePrefix = strtolower(trim((string)$this->namespace, '\\'));
		$controllerFqcnLower = strtolower($this->controllerFqcn);

		$controllerWithoutNamespace = $namespacePrefix !== ''
			? str_replace($namespacePrefix, '', $controllerFqcnLower)
			: $controllerFqcnLower;

		$controllerUri = str_replace('\\', '.', trim($controllerWithoutNamespace, '\\'));

		return $this->module . '.' . $controllerUri;
	}

	public function getMethodUri(string $method): string
	{
		return $this->getUri() . '.' . strtolower($method);
	}

	/**
	 * @return array<string, MethodDescription>
	 */
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

		if (empty($data['controllerFqcn']) || !is_string($data['controllerFqcn']))
		{
			throw new SystemException('Parameter "controller" is required and must be a string.');
		}

		if (isset($data['dtoFqcn']) && $data['dtoFqcn'] !== null && !is_string($data['dtoFqcn']))
		{
			throw new SystemException('Parameter "dtoFqcn" must be a string or null.');
		}

		if (isset($data['namespace']) && $data['namespace'] !== null && !is_string($data['namespace']))
		{
			throw new SystemException('Parameter "namespace" must be a string or null.');
		}

		if (isset($data['methods']) && !is_array($data['methods']))
		{
			throw new SystemException('Parameter "methods" must be an array.');
		}

		if (isset($data['enabled']) && !is_bool($data['enabled']))
		{
			throw new SystemException('Parameter "enabled" must be a bool.');
		}

		return new self(
			module: $data['module'],
			controllerFqcn: $data['controllerFqcn'],
			dtoFqcn: $data['dto'] ?? null,
			namespace: $data['namespace'] ?? null,
			enabled: $data['enabled'] ?? true,
			methods: $data['methods'] ?? [],
		);
	}

	public function toArray(): array
	{
		$data = [
			'module' => $this->module,
			'controllerFqcn' => $this->controllerFqcn,
			'dtoFqcn' => $this->dtoFqcn,
			'namespace' => $this->namespace,
			'enabled' => $this->enabled,
			'methods' => [],
		];

		foreach ($this->methodDescriptions as $methodDescription)
		{
			$data['methods'][$methodDescription->actionUri] = $methodDescription;
		}

		return $data;
	}
}