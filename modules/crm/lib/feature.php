<?php

namespace Bitrix\Crm;

use Bitrix\Crm\Feature\Entity\FeatureRepository;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DI\ServiceLocator;

class Feature
{
	public static function enabled(string $featureClassName): bool
	{
		$featureClassName = self::getFeatureClassName($featureClassName);
		if (!class_exists($featureClassName))
		{
			return true; // feature is already enabled for everybody
		}

		$repository = self::getRepository();
		if ($repository->isEnabledByClass($featureClassName))
		{
			return true;
		}

		// isEnabledByClass() is fail-closed, so false also covers a class that is not a flag at all
		if (!$repository->isFlagClass($featureClassName))
		{
			throw new ArgumentOutOfRangeException('featureClassName');
		}

		return false;
	}

	public static function enable(string $featureClassName): void
	{
		$featureClassName = self::getFeatureClassName($featureClassName);
		if (!class_exists($featureClassName))
		{
			return;
		}

		self::getRepository()->enableByClass($featureClassName);
	}

	public static function disable(string $featureClassName): void
	{
		$featureClassName = self::getFeatureClassName($featureClassName);
		if (!class_exists($featureClassName))
		{
			return;
		}

		self::getRepository()->disableByClass($featureClassName);
	}

	private static function getRepository(): FeatureRepository
	{
		return ServiceLocator::getInstance()->get(FeatureRepository::class);
	}

	private static function getFeatureClassName(string $featureClassName): string
	{
		if (mb_strpos($featureClassName, '\\') === false)
		{
			$featureClassName = '\\Bitrix\\Crm\\Feature\\' . $featureClassName;
		}

		return $featureClassName;
	}
}
