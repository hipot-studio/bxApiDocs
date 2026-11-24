<?php

namespace Bitrix\BIConnector\Access\Superset;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\Main;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\Model\UserAccessItem;
use Bitrix\BIConnector\Access\Rule\VariableRule;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

final class Synchronizer
{
	private const ROLE_WRITER_NAME = 'writer';
	private const ROLE_READER_NAME = 'reader';
	private const ROLE_EMPTY_NAME = 'empty';
	// A sync waits for the one already running instead of failing, while that one is still within the proxy timeout.
	private const SYNC_LOCK_TIMEOUT = 30;

	public function __construct(private readonly int $userId)
	{
	}

	public function sync(): Main\Result
	{
		// Two syncs of one user may reach Superset interleaved and leave the hash of one with the owners of the other.
		$connection = Main\Application::getConnection();
		$lockName = 'biconnector_superset_sync_' . $this->userId;
		if (!$connection->lock($lockName, self::SYNC_LOCK_TIMEOUT))
		{
			$error = new Main\Error("permissions of user {$this->userId} are being synced.");

			return (new Main\Result())->addError($error);
		}

		try
		{
			return $this->syncUnderLock();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function syncUnderLock(): Main\Result
	{
		$syncResult = new Main\Result();

		// Permissions cached earlier in the request may predate a revocation made while it waited for the lock or BI.
		VariableRule::clearCache();
		UserAccessItem::forget($this->userId);

		$user = (new SupersetUserRepository())->getById($this->userId);
		if (!$user || empty($user->clientId))
		{
			$syncResult->addError(new Main\Error("user with id {$this->userId} not found."));
			return $syncResult;
		}

		$accessController = new AccessController($this->userId);

		$viewValues = $accessController->getAllowedDashboardValue(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW);
		$editValues = $accessController->getAllowedDashboardValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);

		$currentHash = $this->calculateHash($viewValues, $editValues);
		if ($user->permissionHash === $currentHash)
		{
			return $syncResult;
		}

		$externalFilter = [];
		if (
			!is_array($editValues)
			|| count($editValues) > 1
			|| current($editValues) !== PermissionDictionary::VALUE_VARIATION_ALL
		)
		{
			$externalFilter = ['=ID' => $editValues];
		}
		$dashboardsExternalIdList = self::getDashboardExternalIdList($externalFilter);

		$role = self::ROLE_EMPTY_NAME;
		if ($editValues)
		{
			$role = self::ROLE_WRITER_NAME;
		}
		elseif ($viewValues)
		{
			$role = self::ROLE_READER_NAME;
		}

		$integrator = IntegratorFactory::getInstance();
		// Superset takes the permissions request by request, so a sync broken halfway must not leave the old hash:
		// permissions returning to the old set would then look delivered.
		$resetResult = SupersetUserTable::updatePermissionHash($user->id, '');
		if (!$resetResult->isSuccess())
		{
			$syncResult->addErrors($resetResult->getErrors());
			return $syncResult;
		}

		$syncProfileResult = $integrator->syncProfile(
			$user,
			[
				'role' => $role,
				'dashboardIdList' => $dashboardsExternalIdList,
			]
		);
		if ($syncProfileResult->hasErrors())
		{
			$syncResult->addErrors($syncProfileResult->getErrors());
			return $syncResult;
		}

		$updateResult = SupersetUserTable::updatePermissionHash($user->id, $currentHash);
		$syncResult->addErrors($updateResult->getErrors());

		return $syncResult;
	}

	/**
	 * Whether Superset already holds the current permissions of the user, so nothing is left to sync.
	 */
	public function isSynchronized(): bool
	{
		$user = (new SupersetUserRepository())->getById($this->userId);
		if (!$user || empty($user->clientId))
		{
			// An account created by a login request alone gets the default roles and no owned dashboards.
			return false;
		}

		$accessController = new AccessController($this->userId);
		$currentHash = $this->calculateHash(
			$accessController->getAllowedDashboardValue(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW),
			$accessController->getAllowedDashboardValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT),
		);

		return $user->permissionHash === $currentHash;
	}

	private static function getDashboardExternalIdList(array $filter = []): array
	{
		$filter += [
			'=STATUS' => SupersetDashboard::getActiveDashboardStatuses(),
			'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
		];

		$parameters = [
			'select' => ['EXTERNAL_ID'],
			'filter' => $filter,
			'cache' => ['ttl' => 3600],
		];


		$dashboards = SupersetDashboardTable::getList($parameters)->fetchAll();

		return array_map('intval', array_column($dashboards, 'EXTERNAL_ID'));
	}

	// Each part is sorted and labeled, so the hash follows the permissions and not the order of database rows.
	private function calculateHash(?array $viewValues, ?array $editValues): string
	{
		return md5(implode('|', [
			'view:' . self::joinIds($viewValues ?? []),
			'edit:' . self::joinIds($editValues ?? []),
			'dashboards:' . self::joinIds(self::getDashboardExternalIdList()),
		]));
	}

	private static function joinIds(array $ids): string
	{
		sort($ids, SORT_STRING);

		return implode(',', $ids);
	}
}
