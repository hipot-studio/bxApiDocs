<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

class AppMarketProfile
{
	public function __construct(
		private App $app,
		private AppType $type,
	)
	{
	}

	public function getApp(): App
	{
		return $this->app;
	}

	public function getType(): AppType
	{
		return $this->type;
	}

	public function getDeliveryMethod(): AppDeliveryMethod
	{
		return match ($this->type) {
			AppType::Configuration,
			AppType::BicDashboard => AppDeliveryMethod::ConfigurationImport,
			default => AppDeliveryMethod::Application,
		};
	}

	public function isUpdatable(): bool
	{
		return match ($this->type) {
			AppType::Configuration,
			AppType::BicDashboard => false,
			default => true,
		};
	}

	public function hasUserInterface(): bool
	{
		return match ($this->type) {
			AppType::OnlyApi,
			AppType::Configuration,
			AppType::BicDashboard => false,
			default => true,
		};
	}

	public function hasManifestAccess(): bool
	{
		return match ($this->type) {
			AppType::Configuration => true,
			default => false,
		};
	}
}
