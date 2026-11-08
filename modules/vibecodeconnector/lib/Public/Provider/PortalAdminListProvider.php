<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Internal\Integration;

class PortalAdminListProvider
{
	/**
	 * Returns the creator and current portal administrators with local profiles.
	 *
	 * @return array{creator: array|null, admins: array}
	 */
	public function get(): array
	{
		$creatorId = $this->getCreatorId();
		$adminIds = array_values(array_unique(array_filter(
			$this->getAdminIds(),
			static fn(int $userId): bool => $userId > 0 && $userId !== $creatorId,
		)));
		$userIds = $adminIds;
		if ($creatorId > 0)
		{
			array_unshift($userIds, $creatorId);
		}

		if ($userIds === [])
		{
			return ['creator' => null, 'admins' => []];
		}

		$profiles = $this->getProfiles($userIds);
		$networkUserIds = $this->getNetworkUserIds(array_keys($profiles));
		$creator = isset($profiles[$creatorId])
			? $this->toProfile($creatorId, $profiles[$creatorId], $networkUserIds)
			: null
		;

		$admins = [];
		foreach ($adminIds as $adminId)
		{
			if (isset($profiles[$adminId]) && $profiles[$adminId]['ACTIVE'] === 'Y')
			{
				$admins[] = $this->toProfile($adminId, $profiles[$adminId], $networkUserIds);
			}
		}

		return ['creator' => $creator, 'admins' => $admins];
	}

	protected function getCreatorId(): int
	{
		return (new Integration\Bitrix24\PortalCreator())->getId();
	}

	protected function getAdminIds(): array
	{
		return $this->isBitrix24Available() ? $this->getBitrix24AdminIds() : $this->getIntranetAdminIds();
	}

	protected function isBitrix24Available(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function getBitrix24AdminIds(): array
	{
		return (new Integration\Bitrix24\PortalAdminUsers())->getAdminIds();
	}

	protected function getIntranetAdminIds(): array
	{
		return (new Integration\Intranet\PortalAdminUsers())->getAdminIds();
	}

	protected function getProfiles(array $userIds): array
	{
		return (new Integration\Main\PortalAdminUsers())->getProfiles($userIds);
	}

	protected function getNetworkUserIds(array $userIds): array
	{
		return (new Integration\Socialservices\PortalNetworkUserIds())->getByPortalUserIds($userIds);
	}

	private function toProfile(int $userId, array $profile, array $networkUserIds): array
	{
		return [
			'networkUserId' => $networkUserIds[$userId] ?? null,
			'portalUserId' => $userId,
			'login' => (string)($profile['LOGIN'] ?? ''),
			'name' => (string)($profile['NAME'] ?? ''),
			'lastName' => (string)($profile['LAST_NAME'] ?? ''),
		];
	}
}
