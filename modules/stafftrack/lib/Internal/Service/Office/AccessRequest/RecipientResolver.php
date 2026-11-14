<?php

namespace Bitrix\StaffTrack\Internal\Service\Office\AccessRequest;

use Bitrix\Main\UserTable;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\DepartmentProvider;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;

/**
 * Who decides on a request of a person to see the staff of an office: the heads of the person, or the
 * administrators of the portal when the person has no active head. The requester never decides on their
 * own request, and an inactive account receives nothing.
 */
class RecipientResolver
{
	/**
	 * Returns the deciders of a request of the given person.
	 *
	 * @param int $requesterId
	 * @return int[] ascending, each once
	 */
	public function resolve(int $requesterId): array
	{
		$deciderIds = $this->keepActive($this->getDepartmentHeadIds($requesterId), $requesterId);
		if ($deciderIds === [])
		{
			$deciderIds = $this->keepActive($this->getAdminIds(), $requesterId);
		}

		sort($deciderIds);

		return $deciderIds;
	}

	/**
	 * Returns whether a person may decide on a request of the given person now: a head of the requester or
	 * an administrator of the portal, and never the requester. A confirmation outlives the role it was sent
	 * for, so the right is asked again at the moment of the decision. An administrator keeps it once the
	 * requester has a head, since the request may have reached the administrators alone.
	 *
	 * @param int $requesterId
	 * @param int $deciderId
	 * @return bool
	 */
	public function canDecide(int $requesterId, int $deciderId): bool
	{
		if ($deciderId <= 0 || $deciderId === $requesterId)
		{
			return false;
		}

		return in_array($deciderId, IdNormalizer::normalize($this->getDepartmentHeadIds($requesterId)), true)
			|| in_array($deciderId, IdNormalizer::normalize($this->getAdminIds()), true)
		;
	}

	/**
	 * @return int[]
	 */
	protected function getDepartmentHeadIds(int $requesterId): array
	{
		return DepartmentProvider::getDepartmentHeadsIdsByUserId($requesterId);
	}

	/**
	 * @return int[]
	 */
	protected function getAdminIds(): array
	{
		return DepartmentProvider::getAdminIds();
	}

	/**
	 * @param int[] $userIds positive identifiers, each once
	 * @return int[] those of them whose accounts are active
	 */
	protected function filterActive(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', 'Y')
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function keepActive(array $userIds, int $requesterId): array
	{
		$userIds = array_values(array_diff(IdNormalizer::normalize($userIds), [$requesterId]));

		return $this->filterActive($userIds);
	}
}
