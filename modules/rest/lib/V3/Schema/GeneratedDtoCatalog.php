<?php

namespace Bitrix\Rest\V3\Schema;

final class GeneratedDtoCatalog
{
	/** @var array<string, string[]> */
	private array $dependenciesByFqcn = [];

	/** @var array<string, string> */
	private array $moduleIdsByFqcn = [];

	/** @var array<string, GeneratedDto> */
	private array $dtosByFqcn = [];

	public function registerDto(GeneratedDto $dto, ?string $moduleId = null): void
	{
		$fqcn = $dto->getFQCN();
		$registeredDto = $this->dtosByFqcn[$fqcn] ?? null;
		if ($registeredDto !== null && $registeredDto != $dto)
		{
			throw new \InvalidArgumentException(sprintf(
				'Generated DTO catalog contains different descriptors for "%s".',
				$fqcn,
			));
		}

		$dependencies = $this->getDependencies($dto);
		if (isset($this->dependenciesByFqcn[$fqcn]) && $this->dependenciesByFqcn[$fqcn] !== $dependencies)
		{
			throw new \InvalidArgumentException(sprintf(
				'Generated DTO catalog contains different dependency indexes for "%s".',
				$fqcn,
			));
		}

		$this->dependenciesByFqcn[$fqcn] = $dependencies;
		if ($moduleId !== null)
		{
			$registeredModuleId = $this->moduleIdsByFqcn[$fqcn] ?? null;
			if ($registeredModuleId !== null && $registeredModuleId !== $moduleId)
			{
				throw new \InvalidArgumentException(sprintf(
					'Generated DTO catalog contains different module ids for "%s".',
					$fqcn,
				));
			}

			$this->moduleIdsByFqcn[$fqcn] = $moduleId;
		}
		$this->dtosByFqcn[$fqcn] = $dto;
	}

	public function hasDto(string $fqcn): bool
	{
		$this->restoreDependencyIndexFromLegacyDescriptors();

		return isset($this->dependenciesByFqcn[$fqcn]);
	}

	public function getDto(string $fqcn): ?GeneratedDto
	{
		return $this->dtosByFqcn[$fqcn] ?? null;
	}

	public function getModuleId(string $fqcn): ?string
	{
		return $this->moduleIdsByFqcn[$fqcn] ?? null;
	}

	/**
	 * @return GeneratedDto[]
	 */
	public function getClosure(string ...$trustedRoots): array
	{
		return $this->getClosureByResolver($this->getDto(...), ...$trustedRoots);
	}

	/**
	 * @param callable(string): ?GeneratedDto $dtoResolver
	 * @return GeneratedDto[]
	 */
	public function getClosureByResolver(callable $dtoResolver, string ...$trustedRoots): array
	{
		$this->restoreDependencyIndexFromLegacyDescriptors();

		$pending = array_values($trustedRoots);
		$pendingIndex = 0;
		$visited = [];
		$result = [];

		while (isset($pending[$pendingIndex]))
		{
			$fqcn = $pending[$pendingIndex];
			$pendingIndex++;
			if (isset($visited[$fqcn]))
			{
				continue;
			}

			$visited[$fqcn] = true;
			$dto = $dtoResolver($fqcn);
			if ($dto === null)
			{
				continue;
			}

			$result[] = $dto;
			foreach ($this->dependenciesByFqcn[$fqcn] ?? [] as $dependency)
			{
				if ($this->hasDto($dependency))
				{
					$pending[] = $dependency;
				}
			}
		}

		return $result;
	}

	/**
	 * @return array{dependencies: array<string, string[]>, modules?: array<string, string>}
	 */
	public function getCacheData(): array
	{
		$this->restoreDependencyIndexFromLegacyDescriptors();

		$cacheData = ['dependencies' => $this->dependenciesByFqcn];
		if ($this->moduleIdsByFqcn !== [])
		{
			$cacheData['modules'] = $this->moduleIdsByFqcn;
		}

		return $cacheData;
	}

	public static function fromCacheData(array $cacheData): self
	{
		$dependencies = $cacheData['dependencies'] ?? null;
		if (!is_array($dependencies))
		{
			throw new \InvalidArgumentException('Generated DTO catalog cache must contain a dependency index.');
		}

		$catalog = new self();
		foreach ($dependencies as $fqcn => $dtoDependencies)
		{
			if (!is_string($fqcn) || !is_array($dtoDependencies))
			{
				throw new \InvalidArgumentException('Generated DTO catalog dependency index is invalid.');
			}

			$catalog->dependenciesByFqcn[$fqcn] = array_values(array_filter($dtoDependencies, 'is_string'));
		}

		$modules = $cacheData['modules'] ?? [];
		if (!is_array($modules))
		{
			throw new \InvalidArgumentException('Generated DTO catalog module index is invalid.');
		}
		foreach ($modules as $fqcn => $moduleId)
		{
			if (!isset($catalog->dependenciesByFqcn[$fqcn]) || !is_string($moduleId) || $moduleId === '')
			{
				throw new \InvalidArgumentException('Generated DTO catalog module index is invalid.');
			}

			$catalog->moduleIdsByFqcn[$fqcn] = $moduleId;
		}

		return $catalog;
	}

	private function getDependencies(GeneratedDto $dto): array
	{
		$dependencies = [];
		foreach ($dto->fields as $field)
		{
			foreach ([$field->getPropertyType(), $field->getElementType()] as $type)
			{
				if ($type !== null)
				{
					$dependencies[$type] = $type;
				}
			}
		}

		return array_values($dependencies);
	}

	private function restoreDependencyIndexFromLegacyDescriptors(): void
	{
		foreach ($this->dtosByFqcn as $fqcn => $dto)
		{
			if (!isset($this->dependenciesByFqcn[$fqcn]))
			{
				$this->dependenciesByFqcn[$fqcn] = $this->getDependencies($dto);
			}
		}
	}
}
