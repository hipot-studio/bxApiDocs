<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Main\Application;
use Closure;

final class AuditLicenseResolver implements AuditLicenseResolverInterface
{
	public const FEATURE_ID = 'disk_audit_log';
	public const BOX_LICENSE_CODE = 'Holding';

	private Closure $featureAvailabilityResolver;
	private Closure $cloudPortalResolver;
	private Closure $boxLicenseCodesResolver;

	public function __construct(
		?callable $featureAvailabilityResolver = null,
		?callable $cloudPortalResolver = null,
		?callable $boxLicenseCodesResolver = null,
	)
	{
		$this->featureAvailabilityResolver = Closure::fromCallable(
			$featureAvailabilityResolver
			?? static fn(string $featureId): bool => Bitrix24Manager::isFeatureEnabled($featureId),
		);
		$this->cloudPortalResolver = Closure::fromCallable(
			$cloudPortalResolver ?? Bitrix24Manager::isEnabled(...),
		);
		$this->boxLicenseCodesResolver = Closure::fromCallable(
			$boxLicenseCodesResolver ?? static fn(): array => Application::getInstance()->getLicense()->getCodes(),
		);
	}

	public function isAvailable(): bool
	{
		if (($this->cloudPortalResolver)())
		{
			return (bool)($this->featureAvailabilityResolver)(self::FEATURE_ID);
		}

		return in_array(self::BOX_LICENSE_CODE, ($this->boxLicenseCodesResolver)(), true);
	}
}
