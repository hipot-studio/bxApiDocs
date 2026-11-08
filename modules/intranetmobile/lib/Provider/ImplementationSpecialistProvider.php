<?php

namespace Bitrix\IntranetMobile\Provider;

use Bitrix\Bitrix24\Integrator;
use Bitrix\Bitrix24\Internal\Entity\User\PartnerInfo;
use Bitrix\Bitrix24\Public\Provider\User\PartnerInfoProvider;
use Bitrix\Intranet\Invitation;
use Bitrix\IntranetMobile\Dto\UserDto;
use Bitrix\IntranetMobile\Repository\UserRepository;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository as MobileUserRepository;

final class ImplementationSpecialistProvider
{
	/**
	 * Lightweight data for the menu card (counts, flags, avatar preview). The per-user companion
	 * loop is intentionally excluded and fetched lazily via getListData() when the list is opened.
	 *
	 * @return array{users: CommonUserDto[], totalCount: int, canInviteMore: bool}
	 */
	public function getSpecialists(): array
	{
		$ids = array_map('intval', Integrator::getIntegratorsId());
		$totalCount = count($ids);

		return [
			'users' => MobileUserRepository::getByIds($ids),
			'totalCount' => $totalCount,
			'canInviteMore' => $this->isMoreAvailable($totalCount),
		];
	}

	/**
	 * Full list dataset: users plus the per-user companion (partner name, pending state, extranet
	 * flag and cancel permission). Fetched on demand when the specialists list is opened.
	 *
	 * @return array{users: CommonUserDto[], extra: array<int, array{integratorName: string, isPending: bool, isExtranetUser: bool, canCancel: bool}>}
	 */
	public function getListData(): array
	{
		$ids = array_map('intval', Integrator::getIntegratorsId());
		$users = MobileUserRepository::getByIds($ids);

		return [
			'users' => $users,
			'extra' => $this->getCompanionData($ids, $users),
		];
	}

	public function canInviteMore(): bool
	{
		return Integrator::isMoreIntegratorsAvailable();
	}

	private function isMoreAvailable(int $count): bool
	{
		return $count < Integrator::INTEGRATOR_MAX_COUNT;
	}

	/**
	 * @param int[] $ids
	 * @param CommonUserDto[] $users
	 * @return array<int, array{integratorName: string, isPending: bool, isExtranetUser: bool, canCancel: bool}>
	 */
	private function getCompanionData(array $ids, array $users): array
	{
		if (empty($ids))
		{
			return [];
		}

		$pendingMap = $this->getPendingMap($ids);
		$integratorNames = $this->getIntegratorNameMap();
		$extranetMap = $this->getExtranetMap($users);

		$companion = [];
		foreach ($ids as $id)
		{
			$isPending = $pendingMap[$id] ?? false;
			$companion[$id] = [
				'integratorName' => $integratorNames[$id] ?? '',
				'isPending' => $isPending,
				'isExtranetUser' => $extranetMap[$id] ?? false,
				'canCancel' => $isPending && Invitation::canDelete(['USER_ID' => $id]),
			];
		}

		return $companion;
	}

	/**
	 * @param CommonUserDto[] $users
	 * @return array<int, bool>
	 */
	private function getExtranetMap(array $users): array
	{
		$map = [];
		foreach ($users as $user)
		{
			$map[$user->id] = (bool)$user->isExtranet;
		}

		return $map;
	}

	/**
	 * @return array<int, string>
	 */
	private function getIntegratorNameMap(): array
	{
		$names = [];
		foreach ((new PartnerInfoProvider())->getList() as $partnerInfo)
		{
			if ($partnerInfo instanceof PartnerInfo && !isset($names[$partnerInfo->userId]))
			{
				$names[$partnerInfo->userId] = $partnerInfo->integratorName;
			}
		}

		return $names;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, bool>
	 */
	private function getPendingMap(array $ids): array
	{
		$pendingMap = [];

		$result = \CUser::GetList(
			'ID',
			'ASC',
			['ID' => implode('|', $ids)],
			['FIELDS' => ['ID', 'ACTIVE', 'CONFIRM_CODE']],
		);
		while ($user = $result->Fetch())
		{
			$pendingMap[(int)$user['ID']] = UserRepository::getEmployeeStatus($user) === UserDto::INVITED;
		}

		return $pendingMap;
	}
}
