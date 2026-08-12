<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Registry;

use Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentAccessGuard;
use Bitrix\Main\Loader;

final class TargetDocumentAccessGuardRegistry
{
	/** @var array<string, TargetDocumentAccessGuard|null> */
	private array $guardsByModule = [];

	public function register(string $documentModule, TargetDocumentAccessGuard $guard): void
	{
		$this->guardsByModule[mb_strtolower($documentModule)] = $guard;
	}

	public function resolve(\CBPActivity $activity): ?TargetDocumentAccessGuard
	{
		$rootDocumentId = $activity->getRootActivity()->getDocumentId();
		$module = mb_strtolower((string)(is_array($rootDocumentId) ? ($rootDocumentId[0] ?? '') : ''));
		if ($module === '')
		{
			return null;
		}

		if (!array_key_exists($module, $this->guardsByModule))
		{
			$this->guardsByModule[$module] = $this->loadGuard($module);
		}

		return $this->guardsByModule[$module];
	}

	private function loadGuard(string $module): ?TargetDocumentAccessGuard
	{
		$className = sprintf(
			'Bitrix\\%s\\Integration\\BizProc\\NodeFilter\\TargetDocumentAccessGuard',
			ucfirst($module),
		);

		if (!Loader::includeModule($module) || !class_exists($className))
		{
			return null;
		}

		$instance = new $className();

		return $instance instanceof TargetDocumentAccessGuard ? $instance : null;
	}
}
