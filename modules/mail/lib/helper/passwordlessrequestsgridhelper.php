<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Mail\Helper\Entity\User\UserProvider;
use Bitrix\Mail\Helper\Mailbox\PasswordlessConnectHelper;

class PasswordlessRequestsGridHelper
{
	private PasswordlessConnectHelper $connectHelper;
	private UserProvider $userProvider;

	public function __construct()
	{
		$this->connectHelper = new PasswordlessConnectHelper();
		$this->userProvider = new UserProvider();
	}

	/**
	 * @param array{
	 *     EMPLOYEE?: string|string[],
	 *     EMAIL?: string,
	 *     STATUS?: string|string[],
	 * } $filterData
	 * @return array<array{
	 *     ID: int,
	 *     EMAIL: string,
	 *     USER_ID: int,
	 *     ACTIVE: string,
	 *     OWNER_DATA: array,
	 *     DATE_SENT: ?int,
	 * }>
	 */
	public function getGridData(int $limit, int $offset, array $filterData = []): array
	{
		$filter = $this->buildFilter($filterData);
		$rawRows = $this->connectHelper->getSentRequests($limit, $offset, $filter);

		if (empty($rawRows))
		{
			return [];
		}

		$userIds = array_unique(array_filter(array_column($rawRows, 'USER_ID')));
		$userEntities = $this->userProvider->getEntitiesInfo($userIds);
		$users = array_map(
			static fn($user) => $user->toArray(),
			$userEntities,
		);

		$rows = [];
		foreach ($rawRows as $raw)
		{
			$dto = PasswordlessRequestRowDto::fromRawMailbox($raw, $users);
			$rows[] = $dto->toGridRow();
		}

		return $rows;
	}

	/**
	 * @param array{
	 *     EMPLOYEE?: string|string[],
	 *     EMAIL?: string,
	 *     STATUS?: string|string[],
	 * } $filterData
	 */
	public function getTotalCount(array $filterData = []): int
	{
		$filter = $this->buildFilter($filterData);

		return $this->connectHelper->getSentRequestsTotalCount($filter);
	}

	/**
	 * @param array{
	 *     EMPLOYEE?: string|string[],
	 *     EMAIL?: string,
	 *     STATUS?: string|string[],
	 * } $filterData
	 * @return array{
	 *     USER_ID?: string|string[],
	 *     EMAIL?: string,
	 *     ACTIVE?: string|string[],
	 * }
	 */
	private function buildFilter(array $filterData): array
	{
		$filter = [];

		if (!empty($filterData['EMPLOYEE']))
		{
			$filter['USER_ID'] = $filterData['EMPLOYEE'];
		}

		if (!empty($filterData['EMAIL']))
		{
			$filter['EMAIL'] = $filterData['EMAIL'];
		}

		if (!empty($filterData['STATUS']))
		{
			$filter['ACTIVE'] = $filterData['STATUS'];
		}

		if (!empty($filterData['FIND']))
		{
			$filter['FIND'] = $filterData['FIND'];
		}

		return $filter;
	}
}
