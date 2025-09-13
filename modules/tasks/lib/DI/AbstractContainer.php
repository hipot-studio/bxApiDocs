<?php

declare(strict_types=1);

namespace Bitrix\Tasks\DI;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\DI\Attribute\Inject;
use Bitrix\Tasks\Internals\Trait\SingletonTrait;
use Bitrix\Tasks\V2\Internal\Exception\DI\CyclicDependencyException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;

abstract class AbstractContainer
{
	use SingletonTrait;

	private static ?array $diConfig = null;

	private ServiceLocator $locator;

	public function getValidationService(): ValidationService
	{
		return $this->getRegisteredObject('main.validation.service');
	}

	public function getRuntimeObjectWithDi(string $className): object
	{
		$className = $this->resolveInitialImplementation($className);

		return $this->buildWithDi($className);
	}

	public function getRegisteredObject(string $idOrClass): object
	{
		return $this->locator->get($idOrClass);
	}

	protected function getRuntimeObject(string|callable $classOrConstructor, string $id, array $args = []): object
	{
		if (!$this->locator->has($id))
		{
			$args = $this->resolveArgs($classOrConstructor, $args);
			$this->locator->addInstanceLazy($id, $args);
		}

		return $this->locator->get($id);
	}

	protected function getDiConfig(): array
	{
		if (static::$diConfig === null)
		{
			static::$diConfig = Configuration::getInstance('tasks')->get('di') ?? [];
		}

		return static::$diConfig;
	}

	private function resolveArgs(string|callable $classOrConstructor, array $args = []): array
	{
		if (is_callable($classOrConstructor))
		{
			return [
				'constructor' => $classOrConstructor,
				'constructorParams' => $args,
			];
		}

		return [
			'className' => $classOrConstructor,
			'constructorParams' => $args,
		];
	}

	private function buildWithDi(string $className, array $callsStack = []): object
	{
		$callsStack[] = $className;
		$reflector = new ReflectionClass($className);
		$constructor = $reflector->getConstructor();

		$constructorArgs = $constructor?->getParameters();
		if (empty($constructorArgs))
		{
			try
			{
				return $this->getRegisteredObject($className);
			}
			catch (ObjectNotFoundException $e)
			{
				$object = $this->resolveLocatorCodeImplementation($reflector);
				if (is_object($object))
				{
					return $object;
				}

				throw $e;
			}
		}

		$args = [];
		foreach ($constructorArgs as $constructorArg)
		{
			$args[$constructorArg->getName()] = $this->resolveDiArgument(
				$constructorArg,
				$callsStack
			);
		}

		$id = $this->generateId($className);

		return $this->getRuntimeObject($className, $id, $args);
	}

	private function resolveDiArgument(
		ReflectionParameter $reflector,
		array $callsStack = []
	): object
	{

		$argument = $this->resolveImplementation($reflector);

		if ($argument === null)
		{
			throw new ObjectNotFoundException('Cannot find implementation in ' . $reflector->getName());
		}

		// built by service locator
		if (is_object($argument))
		{
			return $argument;
		}

		if (in_array($argument, $callsStack, true))
		{
			throw new CyclicDependencyException('Cyclic dependencies are not allowed');
		}

		return $this->buildWithDi($argument, $callsStack);
	}

	private function resolveImplementation(
		ReflectionParameter $reflector,
	): null|string|object
	{
		$type = $reflector->getType()?->getName();
		if ($type === null)
		{
			return null;
		}

		$implementation = $this->resolveLocatorCodeImplementation($reflector);
		if ($implementation !== null)
		{
			return $implementation;
		}

		$implementation = $this->resolveConfigImplementation($type);

		return $implementation ?? $type;
	}

	private function resolveLocatorCodeImplementation(ReflectionParameter|ReflectionClass $reflector): ?object
	{
		$attributes = $reflector->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
		foreach ($attributes as $reflectionAttribute)
		{
			$attribute = $reflectionAttribute->newInstance();

			if ($attribute->externalModule !== null)
			{
				$isLoaded = Loader::includeModule($attribute->externalModule);
				if (!$isLoaded)
				{
					throw new LoaderException('Cannot load module ' . $attribute->externalModule);
				}
			}

			if ($attribute->locatorCode !== null)
			{
				try
				{
					return $this->locator->get($attribute->locatorCode);
				}
				catch (ObjectNotFoundException)
				{
					return null;
				}
			}
		}

		return null;
	}

	private function resolveInitialImplementation(string $className): string
	{
		$diConfig = $this->getDiConfig();

		return $diConfig[$className] ?? $className;
	}

	private function resolveConfigImplementation(string $className): ?string
	{
		$diConfig = $this->getDiConfig();

		return $diConfig[$className] ?? null;
	}

	private function generateId(string $className): string
	{
		return 'runtime_id_' . strtolower($className);
	}

	protected function init(): void
	{
		$this->locator = ServiceLocator::getInstance();
	}
}