<?php

namespace Bitrix\Bizproc\Internal\Service;

use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Trait\SingletonTrait;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\DI\ServiceLocator;
use Psr\Container\ContainerInterface;

class Container
{
	use SingletonTrait;

	private static ?ContainerInterface $serviceLocator = null;

	protected static function getServiceLocator(): ContainerInterface
	{
		if (self::$serviceLocator === null)
		{
			self::$serviceLocator = ServiceLocator::getInstance();
		}

		return self::$serviceLocator;
	}

	private static function getService(string $name): mixed
	{
		$prefix = 'bizproc.';
		if (mb_strpos($name, $prefix) !== 0)
		{
			$name = $prefix . $name;
		}

		$locator = self::getServiceLocator();

		return $locator->has($name)
			? $locator->get($name)
			: null
		;
	}

	public function getComplexActivityService(): ComplexActivityService
	{
		return self::getService('bizproc.service.activity.complex');
	}

	public function getActivitySearcherService(): Searcher
	{
		return static::getService('bizproc.runtime.activitysearcher.searcher');
	}
}
