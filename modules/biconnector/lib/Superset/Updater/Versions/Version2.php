<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main;
use Bitrix\Main\Result;
use Bitrix\Rest;

/**
 * Binds deprecated dashboards to admin and uninstalls their applications (without deleting dashboards).
 * Deprecated dashboards - leads, sales, sales_struct.
 */
final class Version2 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();

		if (
			SupersetInitializer::getSupersetStatus() == SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS
			|| SupersetInitializer::getSupersetStatus() == SupersetInitializer::SUPERSET_STATUS_DELETED
		)
		{
			return $result;
		}

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			$result->addError(new Main\Error('Superset status is not READY'));

			return $result;
		}

		$user = Main\UserGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', 1)
			->whereNull('DATE_ACTIVE_TO')
			->where('USER.ACTIVE', 'Y')
			->where('USER.REAL_USER', 'expr', true)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		if (!$user)
		{
			$result->addError(new Main\Error('No users in admins group were found.'));

			return $result;
		}
		$adminUserId = (int)$user['USER_ID'];
		$user = (new SupersetUserRepository())->getById($adminUserId);

		$deprecatedDashboards = SupersetDashboardTable::getList([
			'select' => ['*', 'APP'],
			'filter' => [
				'=APP_ID' => [
					'bitrix.bic_telephony_ru',
					'bitrix.bic_telephony_en',
					'bitrix.bic_telephony_kz',
				],
			],
		])
			->fetchCollection()
		;

		$integrator = Integrator::getInstance();
		foreach ($deprecatedDashboards as $dashboard)
		{
			if ($dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
			{
				$dashboard
					->setOwnerId($adminUserId)
					->setType(SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
				;

				$setOwnerResult = $integrator->setDashboardOwner($dashboard->getExternalId(), $user);
				if (
					$setOwnerResult->hasErrors()
					&& $setOwnerResult->getStatus() !== IntegratorResponse::STATUS_NOT_FOUND
				)
				{
					$result->addErrors($setOwnerResult->getErrors());

					return $result;
				}

				$appId = $dashboard->getApp()?->getId();
				if ($appId)
				{
					Rest\AppTable::uninstall($appId);
					Rest\AppTable::update(
						$appId,
						['ACTIVE' => 'N', 'INSTALLED' => 'N'],
					);
				}
			}

			if ($dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			{
				$dashboard->setAppId(null);
			}

			$dashboard->save();
		}

		return $result;
	}
}
