<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Registry;

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentResolver;
use Bitrix\Main\Loader;

final class TargetDocumentResolverRegistry
{
	/** @var array<string, TargetDocumentResolver|null> */
	private array $resolversByModule = [];

	public function register(string $documentModule, TargetDocumentResolver $resolver): void
	{
		$this->resolversByModule[mb_strtolower($documentModule)] = $resolver;
	}

	public function resolve(\CBPActivity $activity): ?TargetDocumentResolver
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

	private static function resolveContextDocumentType(\CBPActivity $activity): array
	{
		$parent = $activity->parent;
		if ($parent instanceof FixedDocumentComplexActivity)
		{
			return $parent::getDocumentTypeForNodeAction();
		}

		return $activity->getDocumentType();
	}

	private function loadResolver(string $module): ?TargetDocumentResolver
	{
		$className = sprintf(
			'Bitrix\\%s\\Integration\\BizProc\\NodeFilter\\TargetDocumentResolver',
			ucfirst($module),
		);

		if (!Loader::includeModule($module) || !class_exists($className))
		{
			return null;
		}

		$instance = new $className();

		return $instance instanceof TargetDocumentResolver ? $instance : null;
	}
}
