<?php

namespace Bitrix\BIConnector\Integration\Superset\Stepper;

use Bitrix\Main;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;

/**
 * @deprecated
 */
class DashboardOwner extends Main\Update\Stepper
{
	protected static $moduleId = 'biconnector';
	private const STEPPER_PARAMS = "~dashboard_owner_stepper_params";
	private const STEPPER_IS_FINISH = "~dashboard_owner_stepper_is_finished";

	public static function isFinished(): bool
	{
		return (Main\Config\Option::get(self::$moduleId, self::STEPPER_IS_FINISH, 'N') === 'Y');
	}

	public function execute(array &$option)
	{
		Main\Loader::includeModule('biconnector');

		if (
			self::isFinished()
			|| !SupersetInitializer::isSupersetExist()
			|| SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY
		)
		{
			return self::FINISH_EXECUTION;
		}

		$adminUserId = $this->getAdminUserId();
		if (!$adminUserId)
		{
			return self::FINISH_EXECUTION;
		}

		$user = (new SupersetUserRepository)->getById($adminUserId);
		if ($user && !$user->clientId)
		{
			$this->createUser($adminUserId);
		}

		return self::FINISH_EXECUTION;
	}

	private function createUser(int $userId): void
	{
		$superset = new SupersetController(Integrator::getInstance());
		$superset->createUser($userId);
	}

	private function getAdminUserId(): ?int
	{
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

		if ($user)
		{
			return (int)$user['USER_ID'];
		}

		return null;
	}
}
