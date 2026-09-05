<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Type\Date;

interface IntegratorInterface
{
	// region Portal & instance lifecycle

	/**
	 * Register new portal on proxy-server. On success - got unique portal ID for authentication in proxy.
	 *
	 * On request save unique ID from response to config by Client middleware,
	 * after that portal make verify request to proxy-server, for verify this portal ID
	 *
	 * @see self::verifyPortal()
	 *
	 * @return IntegratorResponse<string>
	 */
	public function registerPortal(): IntegratorResponse;

	/**
	 * Verify portal ID on proxy-server, created by <b>registerPortal</b> action.
	 *
	 * On request proxy-server make verify request to verify.php endpoint and return verify result in this method
	 *
	 * Method for portal ID verify
	 * @see install/public/bitrix/biconstructor/verify.php
	 *
	 * @return IntegratorResponse
	 */
	public function verifyPortal(): IntegratorResponse;

	/**
	 * Returns response with result of start superset.
	 * If status code is OK/IN_PROGRESS - superset was started.
	 *
	 * @param string $biconnectorToken
	 * @return IntegratorResponse<Array<string,string>>
	 */
	public function startSuperset(string $biconnectorToken = ''): IntegratorResponse;

	/**
	 * Returns response with result of freeze superset.
	 * $params['reason'] - reason of freezing superset.
	 * If the reason is "TARIFF" - instanse won't activate automatically.
	 * Use unfreezeSuperset method with same reason to unfreeze instance.
	 *
	 * @param array $params
	 * @return IntegratorResponse<null>
	 */
	public function freezeSuperset(array $params = []): IntegratorResponse;

	/**
	 * Returns response with result of unfreeze superset.
	 * $params['reason'] - reason of previous freezing superset.
	 * If the reason is "TARIFF" - instance will be activated if it was freezed only with TARIFF reason.
	 *
	 * @param array $params
	 * @return IntegratorResponse<null>
	 */
	public function unfreezeSuperset(array $params = []): IntegratorResponse;

	/**
	 * Suspends superset instance while keeping its DB.
	 * Used for PENDING_DELETE scenario.
	 *
	 * @param array $params
	 * @return IntegratorResponse<null>
	 */
	public function suspendSuperset(array $params = []): IntegratorResponse;

	/**
	 * Resumes a previously suspended superset instance.
	 * Used for cancelPendingDelete scenario.
	 *
	 * @param array $params
	 * @return IntegratorResponse<null>
	 */
	public function resumeSuperset(array $params = []): IntegratorResponse;

	/**
	 * Returns response with result of delete superset.
	 * If status code is OK/IN_PROGRESS - superset was deleted.
	 *
	 * @return IntegratorResponse<null>
	 */
	public function deleteSuperset(): IntegratorResponse;

	/**
	 * Change bi token for getting data from apache superset
	 * If response is OK - the token was changed successfully.
	 *
	 * @param string $biconnectorToken
	 * @param bool $arbitrateInstanceStatus Whether the response is allowed to move the local instance
	 *        status. Background synchronization passes false: a gateway failure on a token push must
	 *        not drop a live instance out of READY.
	 * @return IntegratorResponse<Dto\Dashboard>
	 */
	public function changeBiconnectorToken(
		string $biconnectorToken,
		bool $arbitrateInstanceStatus = true,
	): IntegratorResponse;

	/**
	 * Returns response with result of clear cache superset.
	 * If status code is OK - superset cache was clean.
	 *
	 * @return IntegratorResponse<null>
	 */
	public function clearCache(): IntegratorResponse;

	/**
	 * Updates domain connection with new domain if domain was changed.
	 *
	 * @return IntegratorResponse
	 */
	public function refreshDomainConnection(): IntegratorResponse;

	// endregion

	// region Dashboard

	/**
	 * Returns response with list of dashboards info on successful request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param array $ids External ids of dashboards.
	 * @return IntegratorResponse<Dto\DashboardList>
	 */
	public function getDashboardList(array $ids): IntegratorResponse;

	/**
	 * Returns response with list of dashboards info filtered by the given criteria.
	 * If response code is not OK - returns empty data.
	 *
	 * @param array $filter Filter of dashboards. Supported keys:
	 *  - 'ids' (int[]) — include only dashboards with these external ids;
	 *  - 'neqIds' (int[]) — exclude dashboards with these external ids.
	 * @return IntegratorResponse<Dto\DashboardList>
	 */
	public function getDashboardListByFilter(array $filter = []): IntegratorResponse;

	/**
	 * Returns response with dashboard with requested id.
	 *
	 * @param int $dashboardId
	 * @return IntegratorResponse<Dto\Dashboard>
	 */
	public function getDashboardById(int $dashboardId): IntegratorResponse;

	/**
	 * Returns response with dashboard credentials to embed on successful request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param int $dashboardId
	 * @param array $rlsRules Optional RLS rules to include in guest token
	 *         Format: [['dataset' => datasetId, 'clause' => 'SQL WHERE clause'], ...]
	 *
	 * @param int $expSeconds Expiration seconds.
	 * @return IntegratorResponse<Dto\DashboardEmbeddedCredentials>
	 */
	public function getDashboardEmbeddedCredentials(int $dashboardId, array $rlsRules = [], int $expSeconds = 0): IntegratorResponse;

	/**
	 * Returns response with ID of copied dashboard on success request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param int $dashboardId
	 * @param string $name
	 * @return IntegratorResponse
	 */
	public function copyDashboard(int $dashboardId, string $name): IntegratorResponse;

	/**
	 * Returns stream with file of exported dashboard on success request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param int $dashboardId
	 * @param array $dashboardSettings
	 * @return IntegratorResponse
	 */
	public function exportDashboard(int $dashboardId, array $dashboardSettings = []): IntegratorResponse;

	/**
	 * Uses external ids of dashboards.
	 * Returns response with result of deleting dashboards.
	 * If response code is not OK - returns empty data.
	 *
	 * @param array $dashboardIds External ids of dashboards.
	 * @return IntegratorResponse<int>
	 */
	public function deleteDashboard(array $dashboardIds, bool $deleteRelatedEntities = false): IntegratorResponse;

	/**
	 * Returns response with dashboard import result.
	 * If response is OK - dashboard was imported successfully.
	 *
	 * @param string $filePath
	 * @param string $appCode
	 * @param string $type
	 * @param bool $forceImportDatasets
	 * @return IntegratorResponse<Dto\Dashboard>
	 */
	public function importDashboard(string $filePath, string $appCode, string $type = '', bool $forceImportDatasets = false,): IntegratorResponse;

	/**
	 * Returns response with created dashboard result.
	 * If response is OK - dashboard was created successfully.
	 *
	 * @param array $fields
	 * @return IntegratorResponse<Dto\Dashboard>
	 */
	public function createEmptyDashboard(array $fields): IntegratorResponse;

	/**
	 * Update dashboard fields, that allowed in proxy white-list
	 *
	 * @param int $dashboardId external id of edited dashboard
	 * @param array $editedFields fields for edit in superset. Format: *field_name_in_superset* -> *new_value*
	 * @return IntegratorResponse<Array<string|string>> return array of fields that changed
	 */
	public function updateDashboard(int $dashboardId, array $editedFields): IntegratorResponse;

	/**
	 * Returns response with list of dataset info on successful request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param array $ids External ids of charts.
	 * @return IntegratorResponse
	 */
	public function getChartList(array $ids): IntegratorResponse;

	/**
	 * Returns response with list of dataset info on successful request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param array $ids External ids of datasets.
	 * @return IntegratorResponse
	 */
	public function getDatasetList(array $ids): IntegratorResponse;

	/**
	 * Sets owner for dashboard
	 *
	 * @param int $dashboardId
	 * @param User $user
	 * @return IntegratorResponse
	 */
	public function setDashboardOwner(int $dashboardId, User $user): IntegratorResponse;

	/**
	 * Gets dataset list by dashboard id - datasets which are used in dashboard.
	 *
	 * @param int $dashboardId
	 *
	 * @return IntegratorResponse
	 */
	public function getDashboardDatasets(int $dashboardId): IntegratorResponse;

	/**
	 * Gets list of charts and datasets used in dashboards, and shows which dashboards each entity is used in
	 *
	 * @param int[] $dashboardIds
	 * @return IntegratorResponse
	 */
	public function getDashboardReusedObjects(array $dashboardIds): IntegratorResponse;

	/**
	 * Without `$chartIds` returns the dashboard skeleton. With `$chartIds` returns drill-down data.
	 *
	 * @param int[] $chartIds
	 * @param array{by?: string, order?: string} $sort Drill-down sort; ignored in skeleton mode.
	 * @param int|null $limit Drill-down row cap; ignored in skeleton mode.
	 * @param int $offset Drill-down page offset into the sorted rows; ignored in skeleton mode.
	 * @return IntegratorResponse
	 */
	public function getDashboardOverview(
		int $dashboardId,
		array $appliedFilters = [],
		array $urlParams = [],
		int $streamTimeout = 60 * 5,
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
	): IntegratorResponse;

	/**
	 * Gets available values for a dashboard native filter.
	 *
	 * @return IntegratorResponse
	 */
	public function getFilterValues(
		int $dashboardId,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
		int $streamTimeout = 60,
	): IntegratorResponse;

	// endregion

	// region User

	/**
	 * Creates user in Superset
	 *
	 * @param User $user
	 * @return IntegratorResponse
	 */
	public function createUser(User $user): IntegratorResponse;

	/**
	 * Gets login url with jwt
	 *
	 * @return IntegratorResponse
	 */
	public function getLoginUrl(): IntegratorResponse;

	/**
	 * Updates supersetUser
	 *
	 * @param User $user
	 * @return IntegratorResponse
	 */
	public function updateUser(User $user): IntegratorResponse;

	/**
	 * Activates superset user
	 *
	 * @param User $user
	 * @return IntegratorResponse
	 */
	public function activateUser(User $user): IntegratorResponse;

	/**
	 * Deactivates superset user
	 *
	 * @param User $user
	 * @return IntegratorResponse
	 */
	public function deactivateUser(User $user): IntegratorResponse;

	/**
	 * Sets empty role for superset user
	 *
	 * @param User $user
	 * @return IntegratorResponse
	 */
	public function setEmptyRole(User $user): IntegratorResponse;

	/**
	 * Sync roles, owners and so on
	 *
	 * @param User $user
	 * @param array $data
	 * @return IntegratorResponse
	 */
	public function syncProfile(User $user, array $data): IntegratorResponse;

	// endregion

	// region Dataset

	/**
	 * Returns response with dataset info on successful request.
	 *
	 * @param int $id
	 * @return IntegratorResponse
	 */
	public function getDatasetById(int $id): IntegratorResponse;

	/**
	 * Returns response with dataset info on successful request by name.
	 *
	 * @param string $name
	 * @return IntegratorResponse
	 */
	public function getDatasetByName(string $name): IntegratorResponse;

	/**
	 * Adds dataset
	 *
	 * @param array $fields
	 * @return IntegratorResponse
	 */
	public function createDataset(array $fields): IntegratorResponse;

	/**
	 * Updates dataset
	 *
	 * @param int $id
	 * @param array $fields
	 * @return IntegratorResponse
	 */
	public function updateDataset(int $id, array $fields): IntegratorResponse;

	/**
	 * Deletes dataset
	 *
	 * @param int $id
	 * @return IntegratorResponse
	 */
	public function deleteDataset(int $id): IntegratorResponse;

	/**
	 * Gets dataset url for creating chart
	 *
	 * @param int $id
	 * @return IntegratorResponse
	 */
	public function getDatasetUrl(int $id): IntegratorResponse;

	/**
	 * Gets dataset create url
	 *
	 * @param string $datasetName
	 * @param bool $isVirtual
	 * @return IntegratorResponse
	 */
	public function getDatasetCreateUrl(string $datasetName, bool $isVirtual = false): IntegratorResponse;

	/**
	 * Returns response with list of dataset info by table names on successful request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param string $tableName
	 * @return IntegratorResponse
	 */
	public function getDatasetListByTableName(string $tableName): IntegratorResponse;

	/**
	 * Inits a server-side scenario to create or update required system datasets (e.g., for filters).
	 *
	 * @param array $tables
	 * @return IntegratorResponse
	 */
	public function initRequiredDataset(array $tables = []): IntegratorResponse;

	// endregion

	// region Unused elements

	/**
	 * Gets unused elements - dataset which are not used in charts and charts which are not used in dashboards.
	 *
	 * @param array $params ORM params - page, pageSize, filter, order.
	 *
	 * @return IntegratorResponse
	 */
	public function getUnusedElements(array $params): IntegratorResponse;

	/**
	 * @param array $elements Array of elements: [elementId: int, elementType: chart|dataset].
	 *
	 * @return IntegratorResponse
	 */
	public function deleteUnusedElements(array $elements): IntegratorResponse;

	// endregion

	// region Market subscription
	/**
	 * @param Date|null $date
	 *
	 * @return IntegratorResponse
	 */
	public function setExpirationDate(?Date $date): IntegratorResponse;

	/**
	 * @param array $marketDashboardsIdList
	 *
	 * @return IntegratorResponse
	 */
	public function syncMarketDashboards(array $marketDashboardsIdList): IntegratorResponse;

	// endregion

	/**
	 * Sets Superset data timezone.
	 */
	public function setTimezone(string $timezone): IntegratorResponse;
}
