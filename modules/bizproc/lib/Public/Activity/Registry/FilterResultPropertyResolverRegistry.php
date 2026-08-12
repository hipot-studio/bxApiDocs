<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Registry;

use Bitrix\Bizproc\Public\Activity\Interface\FilterResultPropertyResolver;
use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Main\Loader;

final class FilterResultPropertyResolverRegistry
{
	/** @var array<string, FilterResultPropertyResolver|null> */
	private array $resolversByModule = [];

	public function register(string $documentModule, FilterResultPropertyResolver $resolver): void
	{
		$this->resolversByModule[mb_strtolower($documentModule)] = $resolver;
	}

	public function resolve(\CBPActivity $activity): ?FilterResultPropertyResolver
	{
		$documentType = self::resolveContextDocumentType($activity);
		$module = mb_strtolower((string)($documentType[0] ?? ''));
		if ($module === '')
		{
			return null;
		}

		if (!array_key_exists($module, $this->resolversByModule))
		{
			$this->resolversByModule[$module] = $this->loadResolver($module);
		}

		$resolver = $this->resolversByModule[$module];

		return ($resolver !== null && $resolver->supports($activity)) ? $resolver : null;
	}

	public function supportsModule(string $documentModule): bool
	{
		$module = mb_strtolower($documentModule);
		if ($module === '')
		{
			return false;
		}

		if (!array_key_exists($module, $this->resolversByModule))
		{
			$this->resolversByModule[$module] = $this->loadResolver($module);
		}

		return $this->resolversByModule[$module] !== null;
	}

	private static function resolveContextDocumentType(\CBPActivity $activity): array
	{
		if ($activity instanceof FixedDocumentComplexActivity)
		{
			return $activity::getDocumentTypeForNodeAction();
		}

		return $activity->getDocumentType();
	}

	private function loadResolver(string $module): ?FilterResultPropertyResolver
	{
		$className = sprintf(
			'Bitrix\\%s\\Integration\\BizProc\\NodeFilter\\FilterResultPropertyResolver',
			ucfirst($module),
		);

		if (!Loader::includeModule($module) || !class_exists($className))
		{
			return null;
		}

		$instance = new $className();

		return $instance instanceof FilterResultPropertyResolver ? $instance : null;
	}
}
