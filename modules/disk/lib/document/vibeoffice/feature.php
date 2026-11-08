<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\DocumentResolveContext;
use Bitrix\Disk\Driver;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;

final class Feature
{
	public static function isEnabledOnPortal(): bool
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled', 'N') === 'Y';
	}

	public static function isEnabledForContext(?DocumentResolveContext $context): bool
	{
		return FeaturePolicy::isEnabled(
			self::isEnabledOnPortal(),
			static fn (): array => self::getConfiguration()->getEnabledGroupIds(),
			static fn (): ?int => ProjectCohort::resolveGroupId($context),
		);
	}

	private static function getConfiguration(): Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}
}
