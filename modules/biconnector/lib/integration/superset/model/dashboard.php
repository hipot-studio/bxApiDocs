<?php

namespace Bitrix\BIConnector\Integration\Superset\Model;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Public\Command\Share\DeleteSharesCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class Dashboard
{
	/**
	 * Read timeout of the request confirming that superset already keeps the dashboard as a draft,
	 * in seconds. The answer only decides which error an already failed transition reports, so it
	 * is given a fraction of the default budget instead of doubling the wait.
	 */
	private const REMOTE_DRAFT_CHECK_TIMEOUT = 5;

	private ?Dto\DashboardEmbeddedCredentials $embeddedCredentials = null;

	public function __construct(
		private SupersetDashboard $ormObject,
		private ?Dto\Dashboard $dashboardData = null
	)
	{
	}

	public function getId(): int
	{
		return $this->ormObject->getId();
	}

	/**
	 * ID that represents dashboard in superset
	 * @return int|null
	 */
	public function getExternalId(): ?int
	{
		return $this->ormObject->getExternalId();
	}

	public function getTitle(): string
	{
		return $this->dashboardData?->title ?? $this->ormObject->getTitle();
	}

	public function getType(): string
	{
		return $this->ormObject->getType();
	}

	public function getStatus(): string
	{
		return $this->ormObject->getStatus();
	}

	/**
	 * @deprecated Use isCustomDashboard() instead. Will be removed in future updates.
	 * @see self::isCustomDashboard
	 */
	public function isEditable(): bool
	{
		return $this->isCustomDashboard();
	}

	public function isCustomDashboard(): bool
	{
		return $this->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM;
	}

	public function isSystemDashboard(): bool
	{
		return $this->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM;
	}

	public function isMarketDashboard(): bool
	{
		return $this->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET;
	}

	public function isAvailableDashboard(): bool
	{
		return in_array($this->getStatus(), SupersetDashboard::getActiveDashboardStatuses(), true);
	}

	/**
	 * Whether at least one other dashboard was copied from this one.
	 */
	public function hasCopies(): bool
	{
		$copy = SupersetDashboardTable::getList([
			'select' => ['SOURCE_ID'],
			'filter' => ['=SOURCE_ID' => $this->getId()],
			'limit' => 1,
		])->fetch();

		return $copy !== false;
	}

	public function getUrl(): string
	{
		return $this->dashboardData?->url ?? '';
	}

	public function getEditUrl(): string
	{
		return $this->dashboardData?->editUrl ?? '';
	}

	public function setEditUrl(string $editUrl): self
	{
		if ($this->dashboardData !== null)
		{
			$this->dashboardData->editUrl  = $editUrl;
		}

		return $this;
	}

	public function getAppId(): string
	{
		return $this->ormObject->getAppId() ?? '';
	}

	public function getField($fieldName): mixed
	{
		return $this->ormObject->get($fieldName) ?? null;
	}

	public function getOrmObject(): SupersetDashboard
	{
		return $this->ormObject;
	}

	public function reloadOrmObject(): self
	{
		$oldOrmObject = $this->ormObject;
		$this->ormObject = SupersetDashboardTable::getById($this->ormObject->getId())->fetchObject();

		if ($oldOrmObject->isSourceFilled())
		{
			$this->ormObject->fillSource();
		}

		if ($oldOrmObject->isScopeFilled())
		{
			$this->ormObject->fillScope();
		}

		if ($oldOrmObject->isUrlParamsFilled())
		{
			$this->ormObject->fillUrlParams();
		}

		if ($oldOrmObject->isTagsFilled())
		{
			$this->ormObject->fillTags();
		}

		return $this;
	}

	public function getEmbeddedCredentials(): ?Dto\DashboardEmbeddedCredentials
	{
		return $this->embeddedCredentials;
	}

	public function getNativeFiltersConfig(): array
	{
		return $this->dashboardData?->nativeFilterConfig ?? [];
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'ID' => $this->getId(),
			'EXTERNAL_ID' => $this->getExternalId(),
			'STATUS' => $this->getStatus(),
			'URL' => $this->getUrl() ?? '',
			'DETAIL_URL' => $this->ormObject->getDetailUrl()->getUri() ?? '',
			'EDIT_URL' => $this->getEditUrl() ?? '',
			'TITLE' => $this->getTitle() ?? '',
			'SOURCE_ID' => $this->ormObject->getSourceId(),
			'SOURCE_TITLE' => $this->ormObject->getSource()?->getTitle(),
			'APP_ID' => $this->ormObject->getAppId(),
			'TYPE' => $this->getType(),
			'CREATED_BY_ID' => $this->ormObject->get('CREATED_BY_ID'),
			'OWNER_ID' => $this->ormObject->get('OWNER_ID'),
			'DATE_CREATE' => $this->ormObject->get('DATE_CREATE'),
			'DATE_MODIFY' => $this->ormObject->get('DATE_MODIFY'),
		];
	}

	public function getNativeFilter(): string
	{
		return (new EmbeddedFilter\NativeFilterBuilder($this))->getFormattedFilter();
	}

	public function isSupersetDashboardDataLoaded(): bool
	{
		return $this->dashboardData !== null;
	}

	public function isSupersetDashboardCredentialsLoad(): bool
	{
		return $this->embeddedCredentials !== null;
	}

	/**
	 * Update title for dashboard in superset and bitrix
	 *
	 * @param string $title
	 * @return Result
	 */
	public function changeTitle(string $title): Result
	{
		$result = new Result();

		if ($this->ormObject->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			return $result->addError(new Error("Title can be changed only for custom dashboard"));
		}

		$externalId = $this->getOrmObject()->getExternalId();
		if ($externalId <= 0)
		{
			return $result->addError(new Error("Cannot change title without external id"));
		}

		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, ['dashboard_title' => $title]);
		if ($response->getStatus() !== IntegratorResponse::STATUS_OK || $response->hasErrors())
		{
			return $result->addError(new Error("Error while changing title in superset"));
		}

		$changedFields = $response->getData();
		if (!isset($changedFields['dashboard_title']))
		{
			return $result->addError(new Error("Dashboard title not change while update fields"));
		}

		if (isset($this->dashboardData))
		{
			$this->dashboardData->title = $changedFields['dashboard_title'];
		}

		$this->ormObject->setTitle($changedFields['dashboard_title']);
		$this->ormObject->save();

		return $result;
	}

	/**
	 * Publish or make draft dashboard.
	 *
	 * @param bool $published
	 * @return Result
	 */
	public function toggleDraft(bool $published): Result
	{
		$result = new Result();

		if ($this->ormObject->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			return $result->addError(new Error("Changing publish property is available only for custom dashboard"));
		}

		$externalId = $this->getOrmObject()->getExternalId();
		if ($externalId <= 0)
		{
			return $result->addError(new Error("Cannot change publish property without external id"));
		}

		if (!$published)
		{
			return $this->unpublish((int)$externalId);
		}

		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, ['published' => true]);
		if ($response->getStatus() !== IntegratorResponse::STATUS_OK || $response->hasErrors())
		{
			return $result->addError(new Error("Error while changing publish property in superset"));
		}

		$changedFields = $response->getData();
		if (!isset($changedFields['published']))
		{
			return $result->addError(new Error("Published property didn`t change while update fields"));
		}

		$this->ormObject->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_READY);
		$this->ormObject->save();

		return $result;
	}

	/**
	 * Moves dashboard to draft: public share links are revoked first, then the remote
	 * publish flag is dropped, and only then the local status changes. A step starts
	 * only after the previous one is confirmed, so a failure never leaves a dashboard
	 * that looks unpublished while it is still reachable by a live link. The error of
	 * an interrupted transition carries the steps that did complete.
	 */
	private function unpublish(int $externalId): Result
	{
		$result = new Result();

		$revokeResult = (new DeleteSharesCommand(dashboardId: $this->ormObject->getId()))->run();
		if (!$revokeResult->isSuccess())
		{
			return $result->addError(new Error(
				'Cannot revoke public share links of dashboard',
				'shares_revoke_failed',
				['completed' => []],
			));
		}

		$completed = ['shares_revoked'];

		if ($this->ormObject->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		{
			if (!$this->unpublishRemote($externalId) && !$this->remoteAlreadyDraft($externalId))
			{
				return $result->addError(new Error(
					'Error while changing publish property in superset',
					'remote_unpublish_failed',
					['completed' => $completed],
				));
			}
		}

		$completed[] = 'remote_unpublished';

		$this->ormObject->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_DRAFT);
		if (!$this->ormObject->save()->isSuccess())
		{
			return $result->addError(new Error(
				'Cannot save draft status of dashboard',
				'local_status_save_failed',
				['completed' => $completed],
			));
		}

		return $result;
	}

	/**
	 * Whether superset confirmed the dashboard is not published anymore.
	 */
	private function unpublishRemote(int $externalId): bool
	{
		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, ['published' => false]);
		if ($response->getStatus() !== IntegratorResponse::STATUS_OK || $response->hasErrors())
		{
			return false;
		}

		$changedFields = $response->getData();

		return is_array($changedFields)
			&& array_key_exists('published', $changedFields)
			&& $changedFields['published'] === false
		;
	}

	private function remoteAlreadyDraft(int $externalId): bool
	{
		$response = IntegratorFactory::getInstance()->getDashboardById(
			$externalId,
			self::REMOTE_DRAFT_CHECK_TIMEOUT,
		);
		if ($response->hasErrors())
		{
			return false;
		}

		$dashboardData = $response->getData();

		return $dashboardData instanceof Dto\Dashboard && !$dashboardData->published;
	}

	/**
	 * Loads embedded credentials for this dashboard.
	 *
	 * @param array $rlsRules Optional RLS rules for guest token
	 *        Format: [['dataset' => datasetId, 'clause' => 'SQL WHERE clause'], ...]
	 * @return $this
	 */
	public function loadCredentials(array $rlsRules = [], int $expSeconds = 0): self
	{
		$integrator = IntegratorFactory::getInstance();
		$credentialsResponse = $integrator->getDashboardEmbeddedCredentials(
			$this->getExternalId(),
			$rlsRules,
			$expSeconds,
		);

		$credentials = $credentialsResponse->getData();
		if (!empty($credentials))
		{
			$this->embeddedCredentials = $credentials;
		}

		return $this;
	}

	public function loadProxyData(): self
	{
		$integrator = IntegratorFactory::getInstance();
		$integratorResult = $integrator->getDashboardById($this->getExternalId());
		if ($integratorResult->hasErrors())
		{
			return $this;
		}
		$dashboardData = $integratorResult->getData();
		if (!empty($dashboardData))
		{
			$this->dashboardData = $dashboardData;
		}

		return $this;
	}

	public function isUseExternalDatasets(): bool
	{
		if (!$this->isEditable())
		{
			return false;
		}

		$dashboardId = $this->getExternalId();
		if (is_null($dashboardId) || $dashboardId <= 0)
		{
			return false;
		}
		$cacheKey = "biconnector_dashboard_info_use_external_dataset_{$dashboardId}";
		$cacheManager = \Bitrix\Main\Application::getInstance()->getManagedCache();

		if ($cacheManager->read(86400, $cacheKey))
		{
			return $cacheManager->get($cacheKey);
		}

		$getDatasetsResult = IntegratorFactory::getInstance()->getDashboardDatasets($dashboardId);
		if ($getDatasetsResult->hasErrors())
		{
			return false;
		}

		$datasets = $getDatasetsResult->getData()['datasets'];
		if (empty($datasets))
		{
			return false;
		}

		$externalDataset = ExternalDatasetTable::getList([
			'select' => ['id'],
			'filter' => [
				'@NAME' => $datasets,
				'@TYPE' => [Type::Rest->value, Type::Source1C->value]
			],
			'limit' => 1,
		])
			->fetchObject()
		;

		$isUseExternalDatasets = !is_null($externalDataset);
		$cacheManager->set($cacheKey, $isUseExternalDatasets);

		return $isUseExternalDatasets;
	}
}
