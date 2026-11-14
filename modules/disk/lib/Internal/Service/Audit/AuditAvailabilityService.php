<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Configuration;
use Closure;

final class AuditAvailabilityService
{
	private Closure $auditEnabledResolver;
	private Closure $featureEnabledResolver;
	private Closure $licenseAvailabilityResolver;

	public function __construct(
		?AuditSettingsService $settingsService = null,
		?AuditLicenseResolverInterface $licenseResolver = null,
		?callable $auditEnabledResolver = null,
		?callable $featureEnabledResolver = null,
		?callable $licenseAvailabilityResolver = null,
	)
	{
		$settingsService ??= new AuditSettingsService();
		$licenseResolver ??= new AuditLicenseResolver();

		$this->auditEnabledResolver = Closure::fromCallable(
			$auditEnabledResolver ?? static fn(): bool => $settingsService->isEnabled(),
		);
		$this->featureEnabledResolver = Closure::fromCallable(
			$featureEnabledResolver ?? Configuration::isAuditFeatureEnabled(...),
		);
		$this->licenseAvailabilityResolver = Closure::fromCallable(
			$licenseAvailabilityResolver ?? static fn(): bool => $licenseResolver->isAvailable(),
		);
	}

	public function isLicenseAvailable(): bool
	{
		return (bool)($this->licenseAvailabilityResolver)();
	}

	public function isAuditEnabled(): bool
	{
		return (bool)($this->auditEnabledResolver)();
	}

	public function isFeatureEnabled(): bool
	{
		return (bool)($this->featureEnabledResolver)();
	}

	public function canCollect(): bool
	{
		return $this->isFeatureEnabled() && $this->isAuditEnabled();
	}

	public function canView(): bool
	{
		return $this->isFeatureEnabled() && $this->isLicenseAvailable();
	}

	public function canEditSettings(): bool
	{
		return $this->isFeatureEnabled() && $this->isLicenseAvailable();
	}
}
