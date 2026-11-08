<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Controller;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnectorMobile\ActionFilter\MobileBIFeatureAccess;
use Bitrix\BIConnectorMobile\Filter\BiDashboardListContext;
use Bitrix\BIConnectorMobile\Filter\FieldDescriptorNormalizer;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Filter\FilterActionsTrait;
use Bitrix\Mobile\Filter\ListContext;

/**
 * Unified filter of the report list. Transport comes from the core trait; BI has a single
 * root list, so the context carries no addressing of its own.
 */
class Filter extends Base
{
	use FilterActionsTrait;

	public function configureActions(): array
	{
		$actions = $this->configureFilterActions();

		// Portal-wide presets are not published: common presets are not enabled for this grid,
		// so setForAll would write a preset that is never read back.
		unset($actions['setForAll'], $actions['unsetForAll']);

		return $actions;
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		// Order matters: a disabled feature must answer FEATURE_DISABLED, not an upgrade offer.
		$filters[] = new MobileBIFeatureAccess();

		if (Loader::includeModule('biconnector'))
		{
			$filters[] = new BIConstructorAccess();
		}

		return $filters;
	}

	protected function createListContext(array $context): ?ListContext
	{
		if (!Loader::includeModule('biconnector') || !Feature::isDashboardFilterPresetsEnabled())
		{
			return null;
		}

		return new BiDashboardListContext();
	}

	protected function getNormalizer(): FieldDescriptorNormalizer
	{
		return new FieldDescriptorNormalizer();
	}
}
