<?php

namespace Bitrix\Main\Config\Feature;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class Factory
{
	/**
	 * @var array<string, string>|null
	 */
	private ?array $namespaceToModule = null;

	/**
	 * @param class-string $className
	 * @return string|null
	 */
	public function resolveModule(string $className): ?string
	{
		$normalizedClassName = strtolower(trim($className, '\\')) . '\\';
		$parts = explode('\\', $normalizedClassName, 3);

		if ($parts[0] && $parts[1])
		{
			$moduleId = ($parts[0] === 'bitrix')
				? $parts[1]
				: $parts[0] . '.' . $parts[1];

			if (ModuleManager::isModuleInstalled($moduleId))
			{
				return $moduleId;
			}
		}

		return null;
	}

	/**
	 * @param class-string<AbstractFlag> $flag
	 * @return AbstractFlag|null
	 */
	public function resolveFlag(string $flag): ?AbstractFlag
	{
		if (!$this->isFlagExists($flag))
		{
			return null;
		}

		return ServiceLocator::getInstance()->get($flag);
	}

	/**
	 * @param class-string<AbstractRule> $rule
	 * @param array $config
	 * @return AbstractRule|null
	 */
	public function resolveRule(string $rule, array $config = []): ?AbstractRule
	{
		if (!$this->isRuleExists($rule))
		{
			return null;
		}

		return $rule::createFromConfig($config);
	}

	/**
	 * @param class-string<AbstractFlag> $flag
	 * @return bool
	 */
	public function isFlagExists(string $flag): bool
	{
		return $this->isEntityExists($flag, AbstractFlag::class);
	}

	/**
	 * @param class-string<AbstractRule> $rule
	 * @return bool
	 */
	public function isRuleExists(string $rule): bool
	{
		return $this->isEntityExists($rule, AbstractRule::class);
	}

	/**
	 * @param class-string $entity
	 * @param class-string $class
	 * @return bool
	 */
	private function isEntityExists(string $entity, string $class): bool
	{
		if (class_exists($entity) && is_a($entity, $class, true))
		{
			return true;
		}

		if (!$this->includeModule($entity))
		{
			return false;
		}

		if (class_exists($entity) && is_a($entity, $class, true))
		{
			return true;
		}

		return false;
	}

	/**
	 * @param class-string $entity
	 * @return bool
	 */
	private function includeModule(string $entity): bool
	{
		$moduleId = $this->resolveModule($entity);
		if ($moduleId === null)
		{
			return false;
		}

		return Loader::includeModule($moduleId);
	}
}
