<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Crm\Service\Container;

final class DynamicTypeScopePolicy
{
	private static ?self $requestInstance = null;

	private readonly \Closure $dynamicTypesLoader;
	private readonly \Closure $factoryResolver;
	private readonly \Closure $entityTypeExistsResolver;
	private ?array $dynamicTypes = null;
	private ?array $entityTypeIdsByScope = null;
	private array $entityTypeExists = [];
	private array $scopeByEntityTypeId = [];

	public function __construct(
		?\Closure $dynamicTypesLoader = null,
		?\Closure $factoryResolver = null,
		?\Closure $entityTypeExistsResolver = null,
	)
	{
		$hasCustomDynamicTypesLoader = $dynamicTypesLoader !== null;
		$this->dynamicTypesLoader = $dynamicTypesLoader ?? static function (): array {
			return Container::getInstance()
				->getDynamicTypesMap()
				->load([
					'isLoadStages' => false,
					'isLoadCategories' => false,
				])
				->getTypes()
			;
		};
		$this->factoryResolver = $factoryResolver ?? static function (int $entityTypeId): ?object {
			return Container::getInstance()->getFactory($entityTypeId);
		};
		$this->entityTypeExistsResolver = $entityTypeExistsResolver
			?? ($hasCustomDynamicTypesLoader
				? fn(int $entityTypeId): bool => $this->loadedDynamicTypeExists($entityTypeId)
				: static fn(int $entityTypeId): bool => Container::getInstance()
					->getTypeByEntityTypeId($entityTypeId) !== null
			)
		;
	}

	public static function getRequestInstance(): self
	{
		return self::$requestInstance ??= new self();
	}

	public function getEntityTypeIds(DynamicTypeScope $scope): array
	{
		$this->initializeIndex();

		return array_keys($this->entityTypeIdsByScope[$scope->value]);
	}

	public function contains(DynamicTypeScope $scope, int $entityTypeId): bool
	{
		return $this->resolveScope($entityTypeId) === $scope;
	}

	public function getRepresentativeEntityTypeId(DynamicTypeScope $scope): ?int
	{
		$entityTypeIds = $this->getEntityTypeIds($scope);

		return $entityTypeIds[0] ?? null;
	}

	public function filterEntityTypeOptions(DynamicTypeScope $scope, array $entityTypeOptions): array
	{
		$allowedEntityTypeIds = array_fill_keys($this->getEntityTypeIds($scope), true);

		return array_filter(
			$entityTypeOptions,
			static fn(mixed $title, mixed $entityTypeId): bool =>
				!\CCrmOwnerType::isPossibleDynamicTypeId((int)$entityTypeId)
				|| isset($allowedEntityTypeIds[(int)$entityTypeId]),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private function initializeIndex(): void
	{
		if ($this->entityTypeIdsByScope !== null)
		{
			return;
		}

		$entityTypeIdsByScope = [
			DynamicTypeScope::Crm->value => [],
			DynamicTypeScope::AutomatedSolution->value => [],
		];

		foreach ($this->getDynamicTypes() as $dynamicType)
		{
			$entityTypeId = (int)$dynamicType->getEntityTypeId();
			if (!\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
			{
				continue;
			}
			$this->entityTypeExists[$entityTypeId] = true;

			$scope = $this->resolveScope($entityTypeId);
			if ($scope === null)
			{
				continue;
			}

			$entityTypeIdsByScope[$scope->value][$entityTypeId] = true;
		}

		$this->entityTypeIdsByScope = $entityTypeIdsByScope;
	}

	private function resolveScope(int $entityTypeId): ?DynamicTypeScope
	{
		if (!\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return null;
		}
		if (array_key_exists($entityTypeId, $this->scopeByEntityTypeId))
		{
			return $this->scopeByEntityTypeId[$entityTypeId];
		}
		if (!$this->entityTypeExists($entityTypeId))
		{
			$this->scopeByEntityTypeId[$entityTypeId] = null;

			return null;
		}

		$factory = ($this->factoryResolver)($entityTypeId);
		if ($factory === null || !$factory->isBizProcEnabled())
		{
			$this->scopeByEntityTypeId[$entityTypeId] = null;

			return null;
		}

		$scope = $factory->isInCustomSection()
			? DynamicTypeScope::AutomatedSolution
			: DynamicTypeScope::Crm;
		$this->scopeByEntityTypeId[$entityTypeId] = $scope;

		return $scope;
	}

	private function entityTypeExists(int $entityTypeId): bool
	{
		if (!array_key_exists($entityTypeId, $this->entityTypeExists))
		{
			$this->entityTypeExists[$entityTypeId] = ($this->entityTypeExistsResolver)($entityTypeId);
		}

		return $this->entityTypeExists[$entityTypeId];
	}

	private function loadedDynamicTypeExists(int $entityTypeId): bool
	{
		foreach ($this->getDynamicTypes() as $dynamicType)
		{
			if ((int)$dynamicType->getEntityTypeId() === $entityTypeId)
			{
				return true;
			}
		}

		return false;
	}

	private function getDynamicTypes(): array
	{
		return $this->dynamicTypes ??= ($this->dynamicTypesLoader)();
	}
}
