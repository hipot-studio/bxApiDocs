<?php

namespace Bitrix\Intranet\User\Grid\Column\Provider;

use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Main\Grid\Column\DataProvider;

class DataProviderFactory
{
	public function __construct(
		private readonly UserSettings $settings,
	)
	{
	}

	public function create(): DataProvider
	{
		$class = $this->getProviderClass();

		return new $class($this->settings);
	}

	private function getProviderClass(): string
	{
		return match ($this->settings->getView())
		{
			'integrator' => IntegratorUserDataProvider::class,
			default => UserDataProvider::class,
		};
	}
}
