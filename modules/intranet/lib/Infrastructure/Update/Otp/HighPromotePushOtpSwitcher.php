<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Infrastructure\Update\Otp;

use Bitrix\Intranet\Integration\HumanResources\HrUserService;
use Bitrix\Intranet\Internal\Enum\StepperStatus;
use Bitrix\Intranet\Internal\Service\Otp\HighPromotePushOtp;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;
use Bitrix\Security\Mfa\OtpType;
use Bitrix\Security\Mfa\UserTable;
use Bitrix\Security\Mfa\Otp;

class HighPromotePushOtpSwitcher extends Stepper
{
	protected static $moduleId = 'intranet';
	protected int $limit = 20;

	public function execute(array &$option): bool
	{
		if (empty($option))
		{
			$option['steps'] = 0;
			$option['count'] = 1;
			$option['lastId'] = 0;
			$this->getHighPromoteService()->setStepperStatus(StepperStatus::Running);
		}

		if (!Loader::includeModule('security'))
		{
			$this->getHighPromoteService()->setStepperStatus(StepperStatus::Failed);

			return self::FINISH_EXECUTION;
		}

		$dbUserIds = $this->getUserIdsByLastId((int)($option['lastId'] ?? 0));

		if (empty($dbUserIds))
		{
			$this->getHighPromoteService()->setStepperStatus(StepperStatus::Success);

			return self::FINISH_EXECUTION;
		}

		$dbBatchFull = count($dbUserIds) >= $this->limit;
		$dbLastId = $dbUserIds[array_key_last($dbUserIds)];

		$userIds = $this->filterIntranetUsers($dbUserIds);
		$userIds = $this->filterOutLegacyOtpUsers($userIds);

		if (!empty($userIds))
		{
			$result = UserTable::updateMulti($userIds, [
				'TYPE' => OtpType::Push->value,
				'DEACTIVATE_UNTIL' => null,
				'SKIP_MANDATORY' => 'N',
				'ACTIVE' => 'N',
			]);

			$this->clearCacheForUsers($userIds);

			if (!$result->isSuccess())
			{
				$this->getHighPromoteService()->setStepperStatus(StepperStatus::Failed);

				return self::FINISH_EXECUTION;
			}
		}

		$option['lastId'] = $dbLastId;

		if (!$dbBatchFull)
		{
			$this->getHighPromoteService()->setStepperStatus(StepperStatus::Success);

			return self::FINISH_EXECUTION;
		}

		return self::CONTINUE_EXECUTION;
	}

	protected function getUserIdsByLastId(int $lastId): array
	{
		$result = UserTable::query()
			->setSelect(['USER_ID'])
			->addFilter('>USER_ID', $lastId)
			->addFilter('!=TYPE', OtpType::Push->value)
			->addFilter('=USER.IS_REAL_USER', 'Y')
			->setLimit($this->limit)
			->exec()
			->fetchAll()
		;

		return array_map(static fn($item) => (int)$item['USER_ID'], $result);
	}

	private function filterOutLegacyOtpUsers(array $userIds): array
	{
		$allowedUserIds = MobilePush::createByDefault()->getLegacyOtpAllowedUserIds();

		return array_values(
			array_filter($userIds, static fn(int $userId) => !in_array($userId, $allowedUserIds, true)),
		);
	}

	private function filterIntranetUsers(array $userIds): array
	{
		return (new HrUserService())->filterEmployeesByUserIds($userIds);
	}

	private function clearCacheForUsers(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			Otp::cleanCache($userId);
		}
	}

	private function getHighPromoteService(): HighPromotePushOtp
	{
		return new HighPromotePushOtp(MobilePush::createByDefault());
	}
}
