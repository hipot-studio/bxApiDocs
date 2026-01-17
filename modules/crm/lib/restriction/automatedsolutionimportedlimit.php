<?php

namespace Bitrix\Crm\Restriction;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;

class AutomatedSolutionImportedLimit
{
	public const FEATURE_NAME = 'crm_automated_solution_from_marketplace';

	/** @var Feature|string */
	protected Feature|string $feature = Feature::class;
	protected bool $isEnabled;

	public function __construct()
	{
		$this->isEnabled = $this->includeModule();
	}

	protected function includeModule(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	public function isEnabled(): bool
	{
		return $this->isEnabled;
	}

	public function isRestricted(): bool
	{
		return !$this->isFeatureEnabled();
	}

	protected function isFeatureEnabled(): bool
	{
		if ($this->isEnabled())
		{
			return $this->feature::isFeatureEnabled(static::FEATURE_NAME);
		}

		return true;
	}
}
