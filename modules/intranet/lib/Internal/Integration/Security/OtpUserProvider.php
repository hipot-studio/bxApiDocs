<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAccessTable;
use Bitrix\Main\UserTable;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpType;
use Bitrix\Security;

class OtpUserProvider
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('security');
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, UserOtp>
	 */
	public function getOtpDataByUserIds(array $userIds): array
	{
		if (!$this->isAvailable || empty($userIds))
		{
			return [];
		}

		$rows = Security\Mfa\UserTable::query()
			->setSelect(['USER_ID', 'ACTIVE', 'SECRET', 'TYPE', 'DEACTIVATE_UNTIL', 'SKIP_MANDATORY'])
			->whereIn('USER_ID', $userIds)
			->where('USER.REAL_USER', 'expr', true)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			$result[$userId] = new UserOtp(
				userId: $userId,
				isActive: ($row['ACTIVE'] ?? 'N') === 'Y',
				dateDeactivate: $row['DEACTIVATE_UNTIL'] ?? null,
				isInitialized: !empty($row['SECRET']),
				type: isset($row['TYPE']) ? OtpType::tryFrom($row['TYPE']) : null,
				isMandatorySkipped: ($row['SKIP_MANDATORY'] ?? 'N') === 'Y',
			);
		}

		return $result;
	}

	public function getActivePushOtpUserIds(): array
	{
		if (!$this->isAvailable)
		{
			return [];
		}

		$rows = Security\Mfa\UserTable::query()
			->setSelect(['USER_ID'])
			->where('ACTIVE', 'Y')
			->where('TYPE', OtpType::Push->value)
			->where('USER.REAL_USER', 'expr', true)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
	}

	public function getActiveNonPushOtpUserIds(): array
	{
		if (!$this->isAvailable)
		{
			return [];
		}

		$rows = Security\Mfa\UserTable::query()
			->setSelect(['USER_ID'])
			->where('ACTIVE', 'Y')
			->addFilter('!=TYPE', OtpType::Push->value)
			->where('USER.REAL_USER', 'expr', true)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
	}

	public function getAllMandatoryUserIds(): array
	{
		$mandatoryRights = $this->getMandatoryRights();
		if (empty($mandatoryRights))
		{
			return [];
		}

		$rows = UserAccessTable::query()
			->registerRuntimeField($this->getUserReference())
			->setSelect(['USER_ID'])
			->setGroup(['USER_ID'])
			->where('USER_ID', '>', 0)
			->whereIn('ACCESS_CODE', $mandatoryRights)
			->where('USER.REAL_USER', 'expr', true)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
	}

	/**
	 * @param int[] $userIds subset to check
	 * @return array<int, true> userId => true for users in mandatory groups
	 */
	public function getMandatoryUserIdsAmong(array $userIds): array
	{
		$mandatoryRights = $this->getMandatoryRights();
		if (empty($userIds) || empty($mandatoryRights))
		{
			return [];
		}

		$rows = UserAccessTable::query()
			->registerRuntimeField($this->getUserReference())
			->setSelect(['USER_ID'])
			->setGroup(['USER_ID'])
			->where('USER_ID', '>', 0)
			->whereIn('USER_ID', $userIds)
			->whereIn('ACCESS_CODE', $mandatoryRights)
			->where('USER.REAL_USER', 'expr', true)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['USER_ID']] = true;
		}

		return $result;
	}

	public function hasUsersWithExpiringPersonalGrace(int $daysThreshold = 3): bool
	{
		if (!$this->isAvailable)
		{
			return false;
		}

		$mandatoryUserIds = $this->getAllMandatoryUserIds();
		if (empty($mandatoryUserIds))
		{
			return false;
		}

		$activePushUserIds = $this->getActivePushOtpUserIds();
		$needAttentionUserIds = array_diff($mandatoryUserIds, $activePushUserIds);
		if (empty($needAttentionUserIds))
		{
			return false;
		}

		$thresholdDate = DateTime::createFromTimestamp(time() + $daysThreshold * 86400);

		$row = Security\Mfa\UserTable::getList([
			'filter' => [
				'@USER_ID' => $needAttentionUserIds,
				[
					'LOGIC' => 'OR',
					[
						'!DEACTIVATE_UNTIL' => null,
						'<DEACTIVATE_UNTIL' => $thresholdDate,
					],
					[
						'=ACTIVE' => 'N',
						'DEACTIVATE_UNTIL' => null,
						'=SKIP_MANDATORY' => 'N',
					],
				],
			],
			'select' => ['USER_ID'],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	private function getMandatoryRights(): array
	{
		if (!$this->isAvailable)
		{
			return [];
		}

		return Otp::getMandatoryRights();
	}

	private function getUserReference(): Reference
	{
		return (new Reference(
			'USER',
			UserTable::class,
			Join::on('this.USER_ID', 'ref.ID'),
		))->configureJoinType(Join::TYPE_INNER);
	}
}
