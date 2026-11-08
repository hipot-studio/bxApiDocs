<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\ExternalStatLoggerManager;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\DashboardList;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorInterface;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

class UsageStat extends Controller
{
	private const ENTITY_TYPE_DASHBOARD = 'dashboard';
	private const ENTITY_TYPE_CHART = 'chart';
	private const ENTITY_TYPE_DATASET = 'dataset';

	private const KEY_MANAGE_OPERATION = 'biconnector_key_manage';

	public function configureActions(): array
	{
		return [
			'toggleExternalStatCollection' => [
				'prefilters' => $this->getKeyManageActionPreFilters(),
			],
		];
	}

	/**
	 * Access of this action is bound to the key management operation, so the default analytic filters
	 * of the controller must not be applied to it.
	 */
	private function getKeyManageActionPreFilters(): array
	{
		return [
			new Authentication(),
			new HttpMethod([HttpMethod::METHOD_POST]),
			new Csrf(),
		];
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = [
			...parent::getDefaultPreFilters(),
			new ActionFilter\BIConstructorAccess(),
			new ActionFilter\WorkspaceAnalyticAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$filters[] = new \Bitrix\Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	/**
	 * @return array{active: bool, hoursLeft: int}|null Window state after the operation.
	 */
	public function toggleExternalStatCollectionAction(string $enable): ?array
	{
		if (!$this->canManageKeys())
		{
			$this->addError($this->getAccessDeniedError());

			return null;
		}

		if ($enable === 'Y')
		{
			ExternalStatLoggerManager::enable();
		}
		else
		{
			ExternalStatLoggerManager::disable();
		}

		return [
			'active' => ExternalStatLoggerManager::isActive(),
			'hoursLeft' => ExternalStatLoggerManager::getHoursLeft(),
		];
	}

	private function canManageKeys(): bool
	{
		return CurrentUser::get()->canDoOperation(self::KEY_MANAGE_OPERATION);
	}

	private function getAccessDeniedError(): Error
	{
		return new Error(Loc::getMessage('BIC_USAGE_STAT_ACCESS_DENIED'), 'ACCESS_DENIED');
	}

	public function getOpenUrlAction(string $entityType, int $entityId): ?string
	{
		if ($entityId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('BIC_USAGE_STATE_NOT_INITIALIZED')));

			return null;
		}

		if (!SupersetInitializer::isSupersetReady())
		{
			$this->addError(new Error(Loc::getMessage('BIC_USAGE_STATE_NOT_INITIALIZED')));

			return null;
		}

		$integrator = IntegratorFactory::getInstance();
		$editUrl = match ($entityType)
		{
			self::ENTITY_TYPE_DASHBOARD => $this->fetchDashboardEditUrl($integrator, $entityId),
			self::ENTITY_TYPE_CHART => $this->fetchChartEditUrl($integrator, $entityId),
			self::ENTITY_TYPE_DATASET => $this->fetchDatasetEditUrl($integrator, $entityId),
			default => null,
		};

		if ($editUrl === null || $editUrl === '')
		{
			$this->addError(new Error(Loc::getMessage('BIC_USAGE_STATE_NOT_FOUND')));

			return null;
		}

		$loginUrl = (new SupersetController())->getLoginUrl();
		if ($loginUrl !== null && $loginUrl !== '')
		{
			$url = new Uri($loginUrl);
			$url->addParams(['next' => $editUrl]);

			return $url->getLocator();
		}

		return $editUrl;
	}

	private function fetchDashboardEditUrl(IntegratorInterface $integrator, int $id): ?string
	{
		$response = $integrator->getDashboardList([$id]);
		if ($response->hasErrors())
		{
			return null;
		}

		$data = $response->getData();
		if (!$data instanceof DashboardList)
		{
			return null;
		}

		foreach ($data->dashboards as $dashboard)
		{
			if ((int)$dashboard->id === $id)
			{
				return (string)$dashboard->editUrl ?: null;
			}
		}

		return null;
	}

	private function fetchChartEditUrl(IntegratorInterface $integrator, int $id): ?string
	{
		$response = $integrator->getChartList([$id]);
		if ($response->hasErrors())
		{
			return null;
		}

		foreach ((array)$response->getData() as $chart)
		{
			if ((int)($chart['id'] ?? 0) === $id)
			{
				return (string)($chart['edit_url'] ?? '') ?: null;
			}
		}

		return null;
	}

	private function fetchDatasetEditUrl(IntegratorInterface $integrator, int $id): ?string
	{
		$response = $integrator->getDatasetList([$id]);
		if ($response->hasErrors())
		{
			return null;
		}

		foreach ((array)$response->getData() as $dataset)
		{
			if ((int)($dataset['id'] ?? 0) === $id)
			{
				return (string)($dataset['edit_url'] ?? '') ?: null;
			}
		}

		return null;
	}
}
