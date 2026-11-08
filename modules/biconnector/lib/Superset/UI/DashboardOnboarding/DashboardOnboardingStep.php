<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\UI\DashboardOnboarding;

enum DashboardOnboardingStep: string
{
	case Filters = 'filters';
	case Presets = 'presets';
	case Info = 'info';
	case Share = 'share';
	case BitrixGpt = 'bitrixgpt';
	case Edit = 'edit';

	/**
	 * Steps inside the embedded Superset live in a cross-origin iframe, so nothing is asked of
	 * that document: the client covers a band of the frame by the fixed embed layout, and drops
	 * the step when no band is described for it.
	 */
	public function isInsideSuperset(): bool
	{
		return match ($this)
		{
			self::Filters, self::Presets => true,
			default => false,
		};
	}
}
