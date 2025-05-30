<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorEventLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\IO;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class proxyintegrator implements SupersetIntegrator
{
    private const PROXY_ACTION_PING_SUPERSET = '/instance/ping';
    private const PROXY_ACTION_START_SUPERSET = '/instance/start';
    private const PROXY_ACTION_FREEZE_SUPERSET = '/instance/freeze';
    private const PROXY_ACTION_UNFREEZE_SUPERSET = '/instance/unfreeze';
    private const PROXY_ACTION_DELETE_SUPERSET = '/instance/delete';
    private const PROXY_ACTION_CHANGE_BI_TOKEN_SUPERSET = '/instance/changeToken';
    private const PROXY_ACTION_REFRESH_DOMAIN_CONNECTION = '/instance/refreshDomain';
    private const PROXY_ACTION_CLEAR_CACHE = '/instance/clearCache';
    private const PROXY_ACTION_LIST_DASHBOARD = '/dashboard/list';
    private const PROXY_ACTION_DASHBOARD_DETAIL = '/dashboard/get';
    private const PROXY_ACTION_GET_EMBEDDED_DASHBOARD_CREDENTIALS = '/dashboard/embedded/get';
    private const PROXY_ACTION_COPY_DASHBOARD = '/dashboard/copy';
    private const PROXY_ACTION_EXPORT_DASHBOARD = '/dashboard/export';
    private const PROXY_ACTION_DELETE_DASHBOARD = '/dashboard/delete';
    private const PROXY_ACTION_IMPORT_DASHBOARD = '/dashboard/import';
    private const PROXY_ACTION_CREATE_USER = '/user/create';
    private const PROXY_ACTION_GET_LOGIN_URL = '/user/getLoginUrl';
    private const PROXY_ACTION_UPDATE_USER = '/user/update';
    private const PROXY_ACTION_USER_ACTIVATE = '/user/activate';
    private const PROXY_ACTION_USER_DEACTIVATE = '/user/deactivate';
    private const PROXY_ACTION_USER_SET_EMPTY_ROLE = '/user/setEmptyRole';
    private const PROXY_ACTION_USER_SYNC_PROFILE = '/user/syncProfile';
    private const PROXY_ACTION_UPDATE_DASHBOARD = '/dashboard/update';
    private const PROXY_ACTION_IMPORT_DATASET = '/dataset/import';
    private const PROXY_ACTION_CREATE_EMPTY_DASHBOARD = '/dashboard/createEmpty';
    private const PROXY_ACTION_SET_DASHBOARD_OWNER = '/dashboard/setOwner';
    private const PROXY_ACTION_CHANGE_DASHBOARD_OWNER = '/dashboard/changeOwner';

    private static self $instance;

    private ProxySender $sender;
    private IntegratorLogger $logger;

    private bool $skipFields = false;

    private bool $isServiceStatusChecked = false;
    private bool $isServiceAvailable = true;

    private function __construct()
    {
        $this->sender = new ProxySender();
        $this->logger = self::getDefaultLogger();
    }

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getDashboardList(array $ids): IntegratorResponse
    {
        if (empty($ids)) {
            return new ProxyIntegratorResponse(
                data: new Dto\DashboardList(
                    dashboards: [],
                    commonCount: 0,
                )
            );
        }

        $inversedIdList = SupersetDashboardTable::getList([
            'select' => ['EXTERNAL_ID'],
            'filter' => [
                '!@EXTERNAL_ID' => $ids,
            ],
        ])->fetchAll();

        $requestParams = [
            'ids' => $ids,
            'neqIds' => array_column($inversedIdList, 'EXTERNAL_ID'),
        ];

        $result = $this->performRequest(
            action: self::PROXY_ACTION_LIST_DASHBOARD,
            requestParams: $requestParams,
            requiredFields: ['dashboards', 'common_count'],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }
        $resultData = $result->requestResult->getData();

        $innerDashboards = $resultData['data']['dashboards'];
        $commonCount = $resultData['data']['common_count'];
        $dashboards = [];

        $response->setInnerStatus($resultData['status']);

        foreach ($innerDashboards as $dashboardData) {
            $jsonMetadata = $this->decode($dashboardData['json_metadata']) ?? [];
            $dateModify = null;
            if (isset($dashboardData['timestamp_modify'])) {
                $dateModify = DateTime::createFromTimestamp((int) $dashboardData['timestamp_modify']);
            }

            $dashboards[] = new Dto\Dashboard(
                id: $dashboardData['id'],
                title: $dashboardData['title'],
                dashboardStatus: $dashboardData['status'] ?? '',
                url: $dashboardData['url'] ?? '',
                editUrl: $dashboardData['edit_url'] ?? '',
                isEditable: $dashboardData['is_editable'] ?? false,
                nativeFilterConfig: $jsonMetadata['native_filter_configuration'] ?? [],
                dateModify: $dateModify,
            );
        }

        $dashboardList = new Dto\DashboardList(
            dashboards: $dashboards,
            commonCount: $commonCount,
        );

        return $response->setData($dashboardList);
    }

    public function getDashboardById(int $dashboardId): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_DASHBOARD_DETAIL,
            requestParams: ['id' => $dashboardId],
            requiredFields: ['dashboard'],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData();
        $dashboardData = $resultData['data']['dashboard'];

        $dashboard = null;
        if ($dashboardData) {
            $jsonMetadata = Json::decode($dashboardData['json_metadata']) ?? [];
            $dateModify = null;
            if (isset($dashboardData['timestamp_modify'])) {
                $dateModify = DateTime::createFromTimestamp((int) $dashboardData['timestamp_modify']);
            }

            $dashboard = new Dto\Dashboard(
                id: $dashboardData['id'],
                title: $dashboardData['title'],
                dashboardStatus: $dashboardData['status'] ?? '',
                url: $dashboardData['url'] ?? '',
                editUrl: $dashboardData['edit_url'] ?? '',
                isEditable: $dashboardData['is_editable'] ?? false,
                nativeFilterConfig: $jsonMetadata['native_filter_configuration'] ?? [],
                dateModify: $dateModify,
            );
        }

        return $response->setData($dashboard);
    }

    public function getDashboardEmbeddedCredentials(int $dashboardId): IntegratorResponse
    {
        $requestParams = [
            'id' => $dashboardId,
        ];
        $result = $this->performRequest(
            action: self::PROXY_ACTION_GET_EMBEDDED_DASHBOARD_CREDENTIALS,
            requestParams: $requestParams,
            requiredFields: ['uuid', 'guest_token', 'domain'],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $credentialsData = $result->requestResult->getData()['data'];
        $credentials = new Dto\DashboardEmbeddedCredentials(
            uuid: $credentialsData['uuid'],
            guestToken: $credentialsData['guest_token'],
            supersetDomain: $credentialsData['domain'],
        );

        return $response->setData($credentials);
    }

    public function updateUser(Dto\User $user): IntegratorResponse
    {
        $parameters = [
            'email' => $user->email,
            'username' => $user->userName,
            'first_name' => $user->firstName,
            'last_name' => $user->lastName,
        ];

        $result = $this->performRequest(
            action: self::PROXY_ACTION_UPDATE_USER,
            requestParams: ['fields' => $parameters],
            user: $user
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $userCredentialsData = $result->requestResult->getData()['data'];

        return $response->setData($userCredentialsData);
    }

    public function activateUser(Dto\User $user): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_USER_ACTIVATE,
            user: $user
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $userCredentialsData = $result->requestResult->getData()['data'];

        return $response->setData($userCredentialsData);
    }

    public function deactivateUser(Dto\User $user): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_USER_DEACTIVATE,
            user: $user
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $userCredentialsData = $result->requestResult->getData()['data'];

        return $response->setData($userCredentialsData);
    }

    public function setEmptyRole(Dto\User $user): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_USER_SET_EMPTY_ROLE,
            user: $user
        );

        return $result->response;
    }

    public function copyDashboard(int $dashboardId, string $name): IntegratorResponse
    {
        $requestParams = [
            'id' => $dashboardId,
            'name' => $name,
        ];

        $result = $this->performRequest(
            action: self::PROXY_ACTION_COPY_DASHBOARD,
            requestParams: $requestParams,
            requiredFields: ['dashboard'],
        );

        $response = $result->response;

        return $response->setData($result->requestResult->getData()['data']['dashboard']);
    }

    public function exportDashboard(int $dashboardId): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_EXPORT_DASHBOARD,
            requestParams: ['id' => $dashboardId],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $requestResult = $result->requestResult->getData();
        $content = $requestResult['data']['body'];
        $content = base64_decode($content, true);
        if ($content <= 0) {
            $this->logger->logMethodErrors(
                self::PROXY_ACTION_EXPORT_DASHBOARD,
                '400',
                [
                    new Error("File content is empty. DashboardId: {$dashboardId}"),
                ]
            );

            return $response;
        }

        $dashboardName = SupersetDashboardTable::getRow([
            'select' => ['TITLE'],
            'filter' => [
                '=EXTERNAL_ID' => $dashboardId,
            ],
        ])['TITLE'];
        $fileName = $dashboardName.'.zip';

        $filePath = \CTempFile::GetFileName(md5(uniqid('bic', true)));
        $file = new IO\File($filePath);
        $contentSize = $file->putContents($content);

        $file = \CFile::MakeFileArray($filePath);
        $file['MODULE_ID'] = 'biconnector';
        $file['name'] = $fileName;
        if ('' !== \CFile::CheckFile($file, strExt: 'zip')) {
            $this->logger->logMethodErrors(
                self::PROXY_ACTION_EXPORT_DASHBOARD,
                '400',
                [
                    new Error("Exported file was not found. DashboardId: {$dashboardId}"),
                ]
            );

            return $response;
        }

        $fileId = \CFile::SaveFile($file, 'biconnector/dashboard_export');
        if ((int) $fileId <= 0) {
            $this->logger->logMethodErrors(
                self::PROXY_ACTION_EXPORT_DASHBOARD,
                '400',
                [
                    new Error("Exported file was not saved. DashboardId: {$dashboardId}"),
                ]
            );
        }
        $newFile = \CFile::GetByID($fileId)->Fetch();

        $responseData = [
            'filePath' => $newFile['SRC'],
            'contentSize' => $contentSize,
        ];

        return $response->setData($responseData);
    }

    public function deleteDashboard(array $dashboardIds): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_DELETE_DASHBOARD,
            requestParams: ['ids' => $dashboardIds],
        );

        return $result->response;
    }

    public function startSuperset(string $biconnectorToken = ''): IntegratorResponse
    {
        $requestParams = ['biconnectorToken' => $biconnectorToken];
        if (ModuleManager::isModuleInstalled('bitrix24')) {
            $requestParams['userName'] = Application::getConnection()->getDatabase();
        }

        $region = Application::getInstance()->getLicense()->getRegion();
        if (!empty($region)) {
            $requestParams['region'] = $region;
        }

        $result = $this->performRequest(
            action: self::PROXY_ACTION_START_SUPERSET,
            requestParams: $requestParams,
            requiredFields: ['token'],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $responseData = [
            'token' => $result->requestResult->getData()['data']['token'],
            'superset_address' => $result->requestResult->getData()['data']['superset_address'] ?? null,
        ];

        return $response->setData($responseData);
    }

    public function freezeSuperset(array $params = []): IntegratorResponse
    {
        $requestParams = [];
        if (isset($params['reason'])) {
            $requestParams['reason'] = $params['reason'];
        }

        return $this->performRequest(
            action: self::PROXY_ACTION_FREEZE_SUPERSET,
            requestParams: $requestParams,
        )
            ->response
        ;
    }

    public function unfreezeSuperset(array $params = []): IntegratorResponse
    {
        $requestParams = [];
        if (isset($params['reason'])) {
            $requestParams['reason'] = $params['reason'];
        }

        return $this->performRequest(
            action: self::PROXY_ACTION_UNFREEZE_SUPERSET,
            requestParams: $requestParams,
        )
            ->response
        ;
    }

    public function deleteSuperset(): IntegratorResponse
    {
        return $this->performRequest(self::PROXY_ACTION_DELETE_SUPERSET)->response;
    }

    public function changeBiconnectorToken(string $biconnectorToken): IntegratorResponse
    {
        return $this->performRequest(
            action: self::PROXY_ACTION_CHANGE_BI_TOKEN_SUPERSET,
            requestParams: [
                'biconnectorToken' => $biconnectorToken,
            ],
        )
            ->response
        ;
    }

    public function clearCache(): IntegratorResponse
    {
        return $this->performRequest(self::PROXY_ACTION_CLEAR_CACHE)->response;
    }

    public function refreshDomainConnection(): IntegratorResponse
    {
        return $this->performRequest(self::PROXY_ACTION_REFRESH_DOMAIN_CONNECTION)->response;
    }

    public function createUser(Dto\User $user): IntegratorResponse
    {
        $action = self::PROXY_ACTION_CREATE_USER;
        $parameters = [
            'username' => $user->userName,
            'email' => $user->email,
            'first_name' => $user->firstName,
            'last_name' => $user->lastName,
        ];

        $result = $this->sender->performRequest(
            $action,
            ['fields' => $parameters],
            $user
        );

        $performingResult = $this->createPerformingResult(
            $result,
            $action,
            ['client_id']
        );

        $response = $performingResult->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $createUserData = $performingResult->requestResult->getData()['data'];

        return $response->setData($createUserData);
    }

    public function getLoginUrl(): IntegratorResponse
    {
        $action = self::PROXY_ACTION_GET_LOGIN_URL;

        $result = $this->performRequest(
            $action,
            requiredFields: ['url'],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];

        return $response->setData($resultData);
    }

    public function importDashboard(
        string $filePath,
        string $appCode,
    ): IntegratorResponse {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_IMPORT_DASHBOARD,
            requestParams: [
                'filePath' => $filePath,
                'currency' => CultureFormatter::getPortalCurrencySymbol(),
                'appCode' => $appCode,
            ],
            requiredFields: ['dashboards'],
            isMultipart: true,
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];

        return $response->setData($resultData);
    }

    public function importDataset(string $filePath): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_IMPORT_DATASET,
            requestParams: [
                'filePath' => $filePath,
                'currency' => CultureFormatter::getPortalCurrencySymbol(),
            ],
            isMultipart: true,
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];

        return $response->setData($resultData);
    }

    public function createEmptyDashboard(array $fields): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_CREATE_EMPTY_DASHBOARD,
            requestParams: ['fields' => $fields]
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];

        return $response->setData($resultData);
    }

    public function setDashboardOwner(int $dashboardId, Dto\User $user): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_SET_DASHBOARD_OWNER,
            requestParams: ['id' => $dashboardId],
            requiredFields: ['dashboard'],
            user: $user
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];

        return $response->setData($resultData);
    }

    public function syncProfile(Dto\User $user, array $data): IntegratorResponse
    {
        $dashboards = SupersetDashboardTable::getList([
            'select' => ['EXTERNAL_ID'],
            'filter' => [
                '=STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_READY,
                '=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
            ],
            'cache' => ['ttl' => 3_600],
        ])->fetchAll();

        $parameters = [
            'role' => $data['role'],
            'dashboardIdList' => $data['dashboardIdList'],
            'dashboardAllIdList' => array_map('intval', array_column($dashboards, 'EXTERNAL_ID')),
        ];

        $result = $this->performRequest(
            action: self::PROXY_ACTION_USER_SYNC_PROFILE,
            requestParams: ['fields' => $parameters],
            user: $user
        );

        return $result->response;
    }

    public function ping(): bool
    {
        if (SupersetInitializer::SUPERSET_STATUS_LOAD === SupersetInitializer::getSupersetStatus()) {
            return true;
        }

        if (!$this->isServiceStatusChecked) {
            $this->performRequest(self::PROXY_ACTION_PING_SUPERSET);
        }

        return $this->isServiceAvailable;
    }

    public function skipRequireFields(): static
    {
        $this->skipFields = true;

        return $this;
    }

    public function updateDashboard(int $dashboardId, array $editedFields): IntegratorResponse
    {
        $result = $this->performRequest(
            action: self::PROXY_ACTION_UPDATE_DASHBOARD,
            requestParams: [
                'id' => $dashboardId,
                'fields' => $editedFields,
            ],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];
        if (isset($resultData['changed_fields'])) {
            $response->setData($resultData['changed_fields']);
        } else {
            $keys = [];
            foreach ($editedFields as $key => $val) {
                $keys[] = htmlspecialcharsbx($key);
            }

            $keys = implode(', ', $keys);
            $error = new Error("Update dashboard returns empty 'changed_fields'. Try to change: {$keys}");

            $this->logger->logMethodErrors(
                self::PROXY_ACTION_UPDATE_DASHBOARD,
                $response->getStatus(),
                [$error]
            );

            $response->addError($error);
        }

        return $response;
    }

    public function changeDashboardOwner(int $dashboardId, Dto\User $userFrom, Dto\User $userTo): IntegratorResponse
    {
        $parameters = [
            'id' => $dashboardId,
            'userFrom' => $userFrom->clientId,
            'userTo' => $userTo->clientId,
        ];

        $result = $this->performRequest(
            action: self::PROXY_ACTION_CHANGE_DASHBOARD_OWNER,
            requestParams: $parameters,
            requiredFields: ['dashboard'],
        );

        $response = $result->response;
        if ($response->hasErrors()) {
            return $response;
        }

        $resultData = $result->requestResult->getData()['data'];

        return $response->setData($resultData);
    }

    private static function getDefaultLogger(): IntegratorLogger
    {
        return new IntegratorEventLogger();
    }

    private static function createResponse(Result $result, array $requiredFields = []): ProxyIntegratorResponse
    {
        $response = new ProxyIntegratorResponse();

        if (!$result->isSuccess()) {
            $errors = $result->getErrors();
            $response->addError(...$errors);
            $response->setStatus((int) current($errors)->getCode());

            return $response;
        }
        $resultData = $result->getData();
        if (
            ProxyIntegratorResponse::HTTP_STATUS_SERVICE_FROZEN === (int) $resultData['status']
            && SupersetInitializer::isSupersetActive()
        ) {
            $response->setStatus(IntegratorResponse::STATUS_FROZEN);

            return $response;
        }

        if (!empty($requiredFields)) {
            if (!isset($resultData['data'])) {
                return $response
                    ->addError(new Error('Server sends empty data'))
                    ->setStatus(IntegratorResponse::STATUS_SERVER_ERROR)
                ;
            }

            foreach ($requiredFields as $requiredField) {
                if (!isset($resultData['data'][$requiredField])) {
                    $response->addError(new Error("Server response must contain field \"{$requiredField}\""));
                }
            }
        }

        if ($response->hasErrors()) {
            $response->setStatus(IntegratorResponse::STATUS_SERVER_ERROR);
        } else {
            $response->setInnerStatus($resultData['status']);
        }

        return $response;
    }

    private function createPerformingResult(Result $result, string $action, array $requiredFields = []): PerformingResult
    {
        $response = self::createResponse($result, $requiredFields);

        if (IntegratorResponse::STATUS_FROZEN === $response->getStatus() && SupersetInitializer::isSupersetActive()) {
            $this->logger->logMethodInfo($action, $response->getStatus(), 'superset was frozen');
            SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_FROZEN);
            $this->setServiceStatus(true);

            return new PerformingResult(
                response: $response,
                requestResult: $result,
            );
        }
        if (!$this->isStatusUnsuccessful($response->getStatus()) && SupersetInitializer::isSupersetFrozen()) {
            $this->logger->logMethodInfo($action, $response->getStatus(), 'superset was unfrozen');
            SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_READY);
        }

        if ($this->isStatusUnsuccessful($response->getStatus())) {
            $errors = [new Error('Got unsuccessful status from proxy-service')];
            if ($response->hasErrors()) {
                array_push($errors, ...$response->getErrors());
            }

            $this->logger->logMethodErrors($action, $response->getStatus(), $errors);
            $this->setServiceStatus(false);
        } elseif ($response->hasErrors()) {
            $this->logger->logMethodErrors($action, $result->getData()['status'] ?? '400', $response->getErrors());
        } else {
            $this->setServiceStatus(true);
        }

        return new PerformingResult(
            response: $response,
            requestResult: $result,
        );
    }

    /**
     * @param string[] $requiredFields
     */
    private function performRequest(
        string $action,
        array $requestParams = [],
        array $requiredFields = [],
        bool $isMultipart = false,
        ?Dto\User $user = null
    ): PerformingResult {
        if (!$user) {
            $userId = CurrentUser::get()->getId();
            if ($userId) {
                $user = (new SupersetUserRepository())->getById($userId);
            } else {
                $user = (new SupersetUserRepository())->getAdmin();
            }

            if (!$user && $this->isUserRequired($action)) {
                $result = (new Result())->addError(new Error('User not found', ProxyIntegratorResponse::HTTP_STATUS_NOT_FOUND));

                return $this->createPerformingResult($result, $action, $requiredFields);
            }
        }

        if (
            SupersetInitializer::isSupersetActive()
            && $user
            && !$user->clientId
        ) {
            $superset = new SupersetController($this);
            $result = $superset->createUser($user->id);
            if ($result->isSuccess()) {
                $createUserData = $result->getData();
                $user = $createUserData['user'];
            } else {
                return $this->createPerformingResult($result, $action, $requiredFields);
            }
        }

        if ($isMultipart) {
            $result = $this->sender->performMultipartRequest($action, $requestParams, $user);
        } else {
            $result = $this->sender->performRequest($action, $requestParams, $user);
        }

        if ($this->skipFields) {
            $requiredFields = [];
        }

        return $this->createPerformingResult($result, $action, $requiredFields);
    }

    private function isStatusUnsuccessful(int $status): bool
    {
        return ($status >= 500) && (ProxyIntegratorResponse::HTTP_STATUS_SERVICE_FROZEN !== $status);
    }

    private function decode(string $data)
    {
        try {
            return Json::decode($data);
        } catch (ArgumentException $e) {
            return null;
        }
    }

    private function setServiceStatus(bool $isAvailable): void
    {
        $this->isServiceStatusChecked = true;
        $this->isServiceAvailable = $isAvailable;
    }

    /**
     * @throws ArgumentException
     */
    private function isUserRequired(string $action): bool
    {
        $actions = [
            self::PROXY_ACTION_PING_SUPERSET => false,
            self::PROXY_ACTION_START_SUPERSET => false,
            self::PROXY_ACTION_FREEZE_SUPERSET => false,
            self::PROXY_ACTION_UNFREEZE_SUPERSET => false,
            self::PROXY_ACTION_DELETE_SUPERSET => false,
            self::PROXY_ACTION_CHANGE_BI_TOKEN_SUPERSET => false,
            self::PROXY_ACTION_REFRESH_DOMAIN_CONNECTION => false,
            self::PROXY_ACTION_CLEAR_CACHE => false,
            self::PROXY_ACTION_LIST_DASHBOARD => false,
            self::PROXY_ACTION_DASHBOARD_DETAIL => false,
            self::PROXY_ACTION_GET_EMBEDDED_DASHBOARD_CREDENTIALS => false,
            self::PROXY_ACTION_COPY_DASHBOARD => true,
            self::PROXY_ACTION_EXPORT_DASHBOARD => false,
            self::PROXY_ACTION_DELETE_DASHBOARD => false,
            self::PROXY_ACTION_IMPORT_DASHBOARD => false,
            self::PROXY_ACTION_CREATE_USER => true,
            self::PROXY_ACTION_GET_LOGIN_URL => true,
            self::PROXY_ACTION_UPDATE_DASHBOARD => false,
            self::PROXY_ACTION_IMPORT_DATASET => false,
            self::PROXY_ACTION_CREATE_EMPTY_DASHBOARD => true,
            self::PROXY_ACTION_SET_DASHBOARD_OWNER => true,
            self::PROXY_ACTION_CHANGE_DASHBOARD_OWNER => false,
        ];

        if (!\array_key_exists($action, $actions)) {
            throw new ArgumentException('Action "'.$action.'" is not supported', 'action');
        }

        return $actions[$action];
    }
}
