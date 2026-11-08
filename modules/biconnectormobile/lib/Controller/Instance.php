<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Controller;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnectorMobile\ActionFilter\MobileBIFeatureAccess;
use Bitrix\Main\Loader;

class Instance extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$filters = [
			// The feature flag is checked before tariff, tool and rights: a disabled feature must answer
			// FEATURE_DISABLED rather than an upgrade offer.
			new MobileBIFeatureAccess(),
		];

		// The filter of the tariff and the rights belongs to biconnector, so on a portal without it there is
		// nothing to build here - and nothing to guard either: MobileBIFeatureAccess above has already
		// refused the action.
		if (Loader::includeModule('biconnector'))
		{
			$filters[] = new BIConstructorAccess();
		}

		return array_merge(parent::getDefaultPreFilters(), $filters);
	}

	protected function getQueryActionNames(): array
	{
		return ['getStatus'];
	}

	public function getStatusAction(): array
	{
		$screenState = $this->resolveScreenState();
		$status = SupersetInitializer::getSupersetStatus();

		return [
			'status' => $status,
			'isReady' => $status === SupersetInitializer::SUPERSET_STATUS_READY,
			'screenState' => $screenState,
		];
	}

	// Values are consumed by the mobile dashboard-list (resolveInstanceEmptyState).
	// Mirrors the web dashboard controller router (biconnector.apachesuperset.dashboard.controller):
	// the terminal / gating states (deleted, pending delete, unlinked, rebind required) are resolved
	// before the Superset initializer runs, so the status is initialized/checked only for a linked,
	// non-deleted instance.
	private function resolveScreenState(): string
	{
		if (SupersetInitializer::isSupersetDeleted())
		{
			return 'error';
		}

		if (SupersetInitializer::isSupersetPendingDelete())
		{
			return 'error';
		}

		if (!DomainLinkService::getInstance()->isLinked() || SupersetInitializer::isRebindRequired())
		{
			return 'proxy';
		}

		SupersetInitializer::initializeOrCheckSupersetStatus();

		return match (SupersetInitializer::getSupersetStatus())
		{
			SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED => 'limit',
			SupersetInitializer::SUPERSET_STATUS_LOAD => 'loading',
			SupersetInitializer::SUPERSET_STATUS_READY => 'ready',
			SupersetInitializer::SUPERSET_STATUS_ERROR => 'error',
			default => 'unavailable',
		};
	}
}
