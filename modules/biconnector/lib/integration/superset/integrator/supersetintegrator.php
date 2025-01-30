<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

interface supersetintegrator
{
    /**
     * Returns response with list of dashboards info on successful request.
     * If response code is not OK - returns empty data.
     *
     * @param array $ids external ids of dashboards
     *
     * @return IntegratorResponse<Dto\DashboardList>
     */
    public function getDashboardList(array $ids): IntegratorResponse;

    /**
     * Returns response with dashboard with requested id.
     *
     * @return IntegratorResponse<Dto\Dashboard>
     */
    public function getDashboardById(int $dashboardId): IntegratorResponse;

    /**
     * Returns response with dashboard credentials to embed on successful request.
     * If response code is not OK - returns empty data.
     *
     * @return IntegratorResponse<Dto\DashboardEmbeddedCredentials>
     */
    public function getDashboardEmbeddedCredentials(int $dashboardId): IntegratorResponse;

    /**
     * Returns response with ID of copied dashboard on success request.
     * If response code is not OK - returns empty data.
     */
    public function copyDashboard(int $dashboardId, string $name): IntegratorResponse;

    /**
     * Returns stream with file of exported dashboard on success request.
     * If response code is not OK - returns empty data.
     *
     * @return IntegratorResponse<int>
     */
    public function exportDashboard(int $dashboardId): IntegratorResponse;

    /**
     * Uses external ids of dashboards.
     * Returns response with result of deleting dashboards.
     * If response code is not OK - returns empty data.
     *
     * @param array $dashboardIds external ids of dashboards
     *
     * @return IntegratorResponse<int>
     */
    public function deleteDashboard(array $dashboardIds): IntegratorResponse;

    /**
     * Returns response with result of start superset.
     * If status code is OK/IN_PROGRESS - superset was started.
     *
     * @return IntegratorResponse<array<string,string>>
     */
    public function startSuperset(string $biconnectorToken): IntegratorResponse;

    /**
     * Returns response with result of freeze superset.
     * $params['reason'] - reason of freezing superset.
     * If the reason is "TARIFF" - instanse won't activate automatically.
     * Use unfreezeSuperset method with same reason to unfreeze instance.
     *
     * @return IntegratorResponse<null>
     */
    public function freezeSuperset(array $params = []): IntegratorResponse;

    /**
     * Returns response with result of unfreeze superset.
     * $params['reason'] - reason of previous freezing superset.
     * If the reason is "TARIFF" - instance will be activated if it was freezed only with TARIFF reason.
     *
     * @return IntegratorResponse<null>
     */
    public function unfreezeSuperset(array $params = []): IntegratorResponse;

    /**
     * Returns response with result of delete superset.
     * If status code is OK/IN_PROGRESS - superset was deleted.
     *
     * @return IntegratorResponse<null>
     */
    public function deleteSuperset(): IntegratorResponse;

    /**
     * Returns response with result of clear cache superset.
     * If status code is OK - superset cache was clean.
     *
     * @return IntegratorResponse<null>
     */
    public function clearCache(): IntegratorResponse;

    /**
     * Creates user in Superset.
     */
    public function createUser(Dto\User $user): IntegratorResponse;

    /**
     * Gets login url with jwt.
     */
    public function getLoginUrl(): IntegratorResponse;

    /**
     * Updates supersetUser.
     */
    public function updateUser(Dto\User $user): IntegratorResponse;

    /**
     * Activates superset user.
     */
    public function activateUser(Dto\User $user): IntegratorResponse;

    /**
     * Deactivates superset user.
     */
    public function deactivateUser(Dto\User $user): IntegratorResponse;

    /**
     * Sets empty role for superset user.
     */
    public function setEmptyRole(Dto\User $user): IntegratorResponse;

    /**
     * Returns response with dashboard import result.
     * If response is OK - dashboard was imported successfully.
     *
     * @return IntegratorResponse<Dto\Dashboard>
     */
    public function importDashboard(string $filePath, string $appCode): IntegratorResponse;

    /**
     * Returns response with dataset import result.
     * If response is OK - dataset was imported successfully.
     *
     * @return IntegratorResponse<Dto\Dashboard>
     */
    public function importDataset(string $filePath): IntegratorResponse;

    /**
     * Returns response with created dashboard result.
     * If response is OK - dashboard was created successfully.
     *
     * @return IntegratorResponse<Dto\Dashboard>
     */
    public function createEmptyDashboard(array $fields): IntegratorResponse;

    /**
     * Sets owner for dashboard.
     */
    public function setDashboardOwner(int $dashboardId, Dto\User $user): IntegratorResponse;

    /**
     * Sync roles, owners and so on.
     */
    public function syncProfile(Dto\User $user, array $data): IntegratorResponse;

    /**
     * Set option that skip required fields in request and return instance.
     *
     * @return $this
     */
    public function skipRequireFields(): static;

    /**
     * Change bi token for getting data from apache superset
     * If response is OK - the token was changed successfully.
     *
     * @return IntegratorResponse<Dto\Dashboard>
     */
    public function changeBiconnectorToken(string $biconnectorToken): IntegratorResponse;

    /**
     * Returns status of superset service availability.
     * If service available - returns true, false otherwise.
     */
    public function ping(): bool;

    /**
     * Update dashboard fields, that allowed in proxy white-list.
     *
     * @param int   $dashboardId  external id of edited dashboard
     * @param array $editedFields fields for edit in superset. Format: *field_name_in_superset* -> *new_value*
     *
     * @return IntegratorResponse<array<string|string>> return array of fields that changed
     */
    public function updateDashboard(int $dashboardId, array $editedFields): IntegratorResponse;

    /**
     * Changes dashboard owners.
     */
    public function changeDashboardOwner(int $dashboardId, Dto\User $userFrom, Dto\User $userTo): IntegratorResponse;
}
