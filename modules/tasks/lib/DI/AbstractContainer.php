<?php

declare(strict_types=1);

namespace Bitrix\Tasks\DI;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Internals\Trait\SingletonTrait;

abstract class AbstractContainer
{
	use SingletonTrait;

	private ServiceLocator $locator;

	public function getValidationService(): ValidationService
	{
		return $this->getRegisteredObject('main.validation.service');
	}

	protected function getRegisteredObject(string $idOrClass): object
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

	protected function init(): void
	{
		$this->locator = ServiceLocator::getInstance();
	}
}