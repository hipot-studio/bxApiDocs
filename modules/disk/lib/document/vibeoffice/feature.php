<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Config\Feature\VibeofficeFlag;
use Bitrix\Disk\Config\Feature\VibeofficeSharedEditingFlag;
use Bitrix\Disk\Document\DocumentResolveContext;
use Bitrix\Disk\Driver;
use Bitrix\Main\Config;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;

final class Feature
{
	/**
	 * Legacy portal switch, still honoured while portals move to {@see VibeofficeFlag}.
	 * @todo Drop it in the next iteration: then only the flag decides.
	 */
	public const LEGACY_OPTION = 'disk_vibeoffice_enabled';

	/**
	 * The portal switch, evaluated without a user: employees, guests and agents get one answer. The flag of the
	 * registry or, for portals that have not moved to it yet, the legacy option turns VibeOffice on.
	 */
	public static function isEnabledOnPortal(): bool
	{
		return
			Option::get(Driver::INTERNAL_MODULE_ID, self::LEGACY_OPTION, 'N') === 'Y'
			|| Config\Feature::isEnabled(VibeofficeFlag::class, new Config\Feature\Context())
		;
	}

	public static function servesPortalEditing(): bool
	{
		return
			VibeofficeHandler::isEnabled()
			&& !Driver::getInstance()->getDocumentHandlersManager()->isClientServerSelected()
		;
	}

	public static function isSharedEditingEnabled(): bool
	{
		return Config\Feature::isEnabled(VibeofficeSharedEditingFlag::class, new Config\Feature\Context());
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
