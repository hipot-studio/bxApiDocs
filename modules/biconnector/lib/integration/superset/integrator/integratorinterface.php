<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\SqlExecutionContext;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

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
	 * Asks the image to drop the permission cache keys of this portal.
	 *
	 * Idempotent: absent keys are not an error, and the call may be repeated. Touches neither the data cache
	 * nor the caches of slices and chart results - those are cleared by clearCache() and cost far more.
	 *
	 * An instance that does not serve is not an error of the call either, but the caller has to be able to
	 * tell that case apart from a failure: the two hosting modes read it differently. The distinguishing
	 * mark is the instance status the portal keeps, not the status code of the response.
	 *
	 * @see \Bitrix\BIConnector\Access\Table\PermissionCacheResetNotifier
	 *
	 * @return IntegratorResponse<null>
	 */
	public function clearPermissionCache(): IntegratorResponse;

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
	 *  - 'neqIds' (int[]) — exclude dashboards with these external ids;
	 *  - 'ownedByRequestUser' (bool) — keep only dashboards owned by the user of the request.
	 *    The owner is resolved by the remote side, so the portal never passes an external user id.
	 *    While the remote side has not confirmed the filter, the method returns an error response
	 *    instead of a list: an unfiltered list would expose dashboards of other users.
	 * @return IntegratorResponse<Dto\DashboardList>
	 */
	public function getDashboardListByFilter(array $filter = []): IntegratorResponse;

	/**
	 * Returns response with dashboard with requested id.
	 *
	 * @param int $dashboardId
	 * @param int $streamTimeout Read timeout of the remote call in seconds; 0 keeps the default one.
	 *        Honoured by the proxy transport; the self-hosted one reads a local instance.
	 * @param bool $arbitrateServiceFailure Whether a service failure is allowed to move the local
	 *        instance status to ERROR. An unattended schedule passes false: a reading request is
	 *        stopped by the ready gate while the instance is not READY, so such a request would
	 *        never bring the instance back out of the status its own failure had set.
	 * @return IntegratorResponse<Dto\Dashboard>
	 */
	public function getDashboardById(
		int $dashboardId,
		int $streamTimeout = 0,
		bool $arbitrateServiceFailure = true,
	): IntegratorResponse;

	/**
	 * Returns response with dashboard credentials to embed on successful request.
	 * If response code is not OK - returns empty data.
	 *
	 * @param int $dashboardId
	 * @param array $rlsRules Optional RLS rules to include in guest token
	 *         Format: [['dataset' => datasetId, 'clause' => 'SQL WHERE clause'], ...]
	 *
	 * @param int $expSeconds Expiration seconds.
	 * @param Dto\User|null $user Whose identity the guest token carries; the current user when omitted.
	 *        A guest of a public link has no portal user, so the link passes its author here.
	 * @return IntegratorResponse<Dto\DashboardEmbeddedCredentials>
	 */
	public function getDashboardEmbeddedCredentials(
		int $dashboardId,
		array $rlsRules = [],
		int $expSeconds = 0,
		?Dto\User $user = null,
	): IntegratorResponse;

	/**
	 * @return IntegratorResponse<Dto\DashboardEmbeddedCredentials>
	 */
	public function getDemoEmbeddedCredentials(string $dashboardCode, string $lang): IntegratorResponse;

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
	 * Returns response with the list of every physical (non-virtual) dataset of the instance.
	 * If response code is not OK - returns empty data.
	 *
	 * @return IntegratorResponse
	 */
	public function getPhysicalDatasetList(): IntegratorResponse;

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
	 * @param User|null $user The person the data is read for: while the table permission axis is on, Superset runs
	 *        the request as this user (act-as) and the read is refused without one; without the axis the read
	 *        goes under the service account and the user is optional.
	 * @param bool $arbitrateServiceFailure Same meaning as in getDashboardById().
	 * @return IntegratorResponse
	 */
	public function getDashboardOverview(
		?User $user,
		int $dashboardId,
		array $appliedFilters = [],
		array $urlParams = [],
		int $streamTimeout = 60 * 5,
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
		bool $arbitrateServiceFailure = true,
	): IntegratorResponse;

	/**
	 * Gets available values for a dashboard native filter.
	 *
	 * @param User|null $user The person the values are read for, as in getDashboardOverview().
	 * @param bool $arbitrateServiceFailure Same meaning as in getDashboardById().
	 * @return IntegratorResponse
	 */
	public function getFilterValues(
		?User $user,
		int $dashboardId,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
		int $streamTimeout = 60,
		bool $arbitrateServiceFailure = true,
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

	// region Table

	/**
	 * Returns response with the list of physical tables available in the BI database.
	 *
	 * @param User|null $user The person the list is built for, as in getDashboardOverview().
	 * @param string|null $schema Schema to filter tables by (defaults to bitrix24).
	 * @return IntegratorResponse
	 */
	public function listTables(?User $user, ?string $schema = null): IntegratorResponse;

	/**
	 * Returns response with the columns/metadata of a physical table in the BI database.
	 *
	 * @param User|null $user The person the metadata is read for, as in getDashboardOverview().
	 * @param string $tableName Physical table name.
	 * @param string|null $schema Schema of the table (defaults to bitrix24).
	 * @return IntegratorResponse
	 */
	public function getTableMetadata(?User $user, string $tableName, ?string $schema = null): IntegratorResponse;

	// endregion

	// region SQL Lab

	/**
	 * Executes a read-only SQL query through Superset SQL Lab and returns rows and columns.
	 *
	 * @param string $sql Raw SQL SELECT to execute against the BI database.
	 * @param int|null $limit Optional row cap (queryLimit).
	 * @param SqlExecutionContext|null $context Explicit execution channel and user.
	 * @return IntegratorResponse
	 */
	public function executeSql(
		string $sql,
		?int $limit = null,
		?SqlExecutionContext $context = null,
	): IntegratorResponse;

	// endregion

	// region Dataset

	/**
	 * Returns response with dataset info on successful request.
	 *
	 * @param int $id
	 * @param User|null $user When set, Superset reads the dataset as this user (act-as);
	 *        null — as the service account, for callers that are not acting for a person.
	 * @return IntegratorResponse
	 */
	public function getDatasetById(int $id, ?User $user = null): IntegratorResponse;

	/**
	 * Returns response with dataset info on successful request by name.
	 *
	 * @param string $name
	 * @return IntegratorResponse
	 */
	public function getDatasetByName(string $name): IntegratorResponse;

	/**
	 * Returns response with the list of datasets in Superset.
	 *
	 * When $pageSize is given the call is bounded to a single Superset page and the
	 * response carries `datasets` plus a `count` holding the total number of
	 * matching datasets — use this for paginated reads so the request cost does not
	 * grow with the number of datasets on the instance. Without $pageSize the full
	 * list is walked and a flat array of datasets is returned (kept for the by-ids /
	 * idempotency callers).
	 *
	 * @param int[] $ids Optional filter: only datasets with these ids.
	 * @param int[] $neqIds Optional filter: exclude datasets with these ids.
	 * @param int|null $page Zero-based page index (bounded mode).
	 * @param User|null $user The person the list is built for, as in getDashboardOverview().
	 * @param int|null $pageSize Page size; presence switches on bounded mode.
	 * @return IntegratorResponse
	 */
	public function listDatasets(
		?User $user,
		array $ids = [],
		array $neqIds = [],
		?int $page = null,
		?int $pageSize = null
	): IntegratorResponse;

	/**
	 * Adds dataset
	 *
	 * @param array $fields
	 * @return IntegratorResponse
	 */
	public function createDataset(array $fields): IntegratorResponse;

	/**
	 * Creates a virtual dataset (SQL-backed view) in Superset based on a source table.
	 * Superset creates it as the user (act-as), so the permissions on tables of the user apply.
	 *
	 * @param User $user Employee the dataset is created for.
	 * @param string $datasetName Display name of the new dataset.
	 * @param string $sourceTable Source physical table name in the BI database.
	 * @param string[] $columns Columns to project from the source table.
	 * @param string|null $sourceSchema Source table schema (defaults to bitrix24).
	 * @return IntegratorResponse
	 */
	public function createVirtualDataset(
		User $user,
		string $datasetName,
		string $sourceTable,
		array $columns,
		?string $sourceSchema = null,
	): IntegratorResponse;

	/**
	 * Creates a virtual dataset (SQL-backed view) in Superset from a raw SQL query.
	 * Superset creates it as the user (act-as), so the permissions on tables of the user apply.
	 *
	 * @param User $user Employee the dataset is created for.
	 * @param string $datasetName Display name of the new dataset.
	 * @param string $sql Raw SQL SELECT used as the dataset source.
	 * @return IntegratorResponse
	 */
	public function createDatasetFromSql(User $user, string $datasetName, string $sql): IntegratorResponse;

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

	// region Chart

	/**
	 * Returns the list of charts in Superset.
	 *
	 * When $pageSize is given the call is bounded to a single Superset page and the
	 * response `count` holds the total number of matching charts — use this for
	 * paginated reads and name lookups so the request cost does not grow with the
	 * number of charts on the instance. Without $pageSize the full list is walked
	 * (kept for the by-ids usage-statistics caller).
	 *
	 * @param int[] $ids Optional filter: only charts with these ids.
	 * @param string|null $nameFilter Optional filter: charts whose name contains this.
	 * @param int|null $page Zero-based page index (bounded mode).
	 * @param User|null $user The person the list is built for, as in getDashboardOverview().
	 * @param int|null $pageSize Page size; presence switches on bounded mode.
	 * @return IntegratorResponse
	 */
	public function listCharts(
		?User $user,
		array $ids = [],
		?string $nameFilter = null,
		?int $page = null,
		?int $pageSize = null
	): IntegratorResponse;

	/**
	 * Returns chart config (slice_name, viz_type, datasource, params, etc.) by id.
	 *
	 * @param int $id
	 * @param User|null $user When set, Superset reads the chart as this user (act-as);
	 *        null — as the service account, for callers that are not acting for a person.
	 * @return IntegratorResponse
	 */
	public function getChartById(int $id, ?User $user = null): IntegratorResponse;

	/**
	 * Creates a new chart.
	 *
	 * Payload matches Superset ChartPostSchema (slice_name, datasource_id, datasource_type,
	 * viz_type, params (JSON string), query_context (JSON string), description, ...).
	 *
	 * @param array $payload
	 * @return IntegratorResponse
	 */
	public function createChart(array $payload): IntegratorResponse;

	/**
	 * Updates an existing chart with the given fields.
	 *
	 * @param int $id
	 * @param array $fields
	 * @return IntegratorResponse
	 */
	public function updateChart(int $id, array $fields): IntegratorResponse;

	/**
	 * Deletes a chart by id.
	 *
	 * @param int $id
	 * @return IntegratorResponse
	 */
	public function deleteChart(int $id): IntegratorResponse;

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

	// region Self-hosted license

	/**
	 * Sends the self-hosted license extension expiration to the instance.
	 *
	 * Applicable to a self-hosted instance only: a cloud portal has no such license and answers with an error.
	 * Null is refused by the receiving side and sends nothing: the absence of a term is asked for by the reset of the
	 * license state and not by a write of an empty value.
	 *
	 * The exact time of day matters: the portal and the instance must compare the same moment, so the
	 * argument is a DateTime and never a Date, which would zero the time out.
	 *
	 * @param DateTime|null $date
	 *
	 * @return IntegratorResponse
	 */
	public function setSelfHostedLicenseExpiration(?DateTime $date): IntegratorResponse;

	/**
	 * Hands the term of the boxed portal license over to the instance. A term of its own: an instance closes
	 * itself when the license of the product is over, and renewing the extension does not help there.
	 *
	 * @param DateTime|null $date
	 *
	 * @return IntegratorResponse
	 */
	public function setBoxLicenseExpiration(?DateTime $date): IntegratorResponse;

	/**
	 * Tells the instance whether the edition of this box allows the local mode at all. Not a term: the edition of a
	 * box has no date, and only the portal knows it, so the instance is told the verdict and keeps it.
	 *
	 * @param bool $isAllowed
	 *
	 * @return IntegratorResponse
	 */
	public function setSelfHostedEditionVerdict(bool $isAllowed): IntegratorResponse;

	/**
	 * Wipes both terms kept by the instance, the extension and the boxed portal alike, and leaves it not connected.
	 *
	 * Applicable to a self-hosted instance only. Asked before the portal leaves the local mode and never after it:
	 * an instance that keeps a term would go on working once switched back on, while the portal is already paying
	 * for the cloud.
	 *
	 * @return IntegratorResponse
	 */
	public function resetSelfHostedLicense(): IntegratorResponse;

	// endregion

	/**
	 * Sets Superset data timezone.
	 */
	public function setTimezone(string $timezone): IntegratorResponse;
}
