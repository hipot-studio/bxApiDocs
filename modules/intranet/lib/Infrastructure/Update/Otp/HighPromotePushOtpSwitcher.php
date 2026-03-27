<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Infrastructure\Update\Otp;

use Bitrix\Intranet\Internal\Enum\StepperStatus;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Service\Otp\HighPromotePushOtp;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;
use Bitrix\Security\Mfa\OtpType;
use Bitrix\Security\Mfa\UserTable;

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

		$userIds = $this->getUserIdsByLastId((int)($option['lastId'] ?? 0));

		if (empty($userIds))
		{
			$this->getHighPromoteService()->setStepperStatus(StepperStatus::Success);

			return self::FINISH_EXECUTION;
		}

		$result = UserTable::updateMulti($userIds, [
			'TYPE' => OtpType::Push->value,
			'DEACTIVATE_UNTIL' => null,
			'SKIP_MANDATORY' => 'N',
			'ACTIVE' => 'N',
		]);

		if ($result->isSuccess())
		{
			$this->clearCacheForUsers($userIds);
		}

		if (count($userIds) < $this->limit || !$result->isSuccess())
		{
			$this->getHighPromoteService()->setStepperStatus(StepperStatus::Success);

			return self::FINISH_EXECUTION;
		}

		$option['lastId'] = $userIds[array_key_last($userIds)];

		return self::CONTINUE_EXECUTION;
	}

	protected function getUserIdsByLastId(int $lastId): array
	{
		$result = UserTable::query()
			->setSelect(['USER_ID'])
			->addFilter('>USER_ID', $lastId)
			->addFilter('!=TYPE', OtpType::Push->value)
			->setLimit($this->limit)
			->exec()
			->fetchAll();

		return array_map(static fn($item) => (int)$item['USER_ID'], $result);
	}

	private function clearCacheForUsers(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			PersonalOtp::clearCache($userId);
		}
	}

	private function getHighPromoteService(): HighPromotePushOtp
	{
		return new HighPromotePushOtp(MobilePush::createByDefault());
	}
}
