<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Provider;

use Bitrix\Main\Type\Collection;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Helper\InstanceTrait;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\BotService;

class EmployeeProvider
{
	protected const CACHE_TTL = 60;

	use InstanceTrait;

	/**
	 * Returns array
	 * [
	 *    [employeeIds]
	 *    [guestIds]
	 * ]
	 */
	public function splitIntoEmployeesAndGuests(array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		if (empty($userIds))
		{
			return [
				0 => [],
				1 => [],
			];
		}

		$employees = UserTable::query()
				->addSelect('ID')
				->addFilter('!UF_DEPARTMENT', false)
				->setCacheTtl(10)
				->whereIn('ID', $userIds)
				->exec()
				->fetchAll()
		;

		$employeeIds = array_column($employees, 'ID');

		Collection::normalizeArrayValuesByInt($employeeIds, false);

		$guestIds = array_diff($userIds, $employeeIds);

		return [
			0 => $employeeIds,
			1 => $guestIds,
		];
	}

	public function splitIntoEmployeesGuestsAndBots(array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		$employeeIds = [];
		$guestIds = [];
		$botIds = [];

		if (empty($userIds))
		{
			return [$employeeIds, $guestIds, $botIds];
		}

		$query =
			UserTable::query()
				->addSelect('ID')
				->setCacheTtl(10)
				->whereIn('ID', $userIds)
		;

		$botExternalAuthId = Container::getInstance()->get(BotService::class)->getExternalAuthId();
		if ($botExternalAuthId !== null)
		{
			$query
				->addSelect('EXTERNAL_AUTH_ID')
				->addFilter(null, [
					'LOGIC' => 'OR',
					['!UF_DEPARTMENT' => false],
					['=EXTERNAL_AUTH_ID' => $botExternalAuthId],
				])
			;
		}
		else
		{
			$query->addFilter('!UF_DEPARTMENT', false);
		}

		$result = $query->exec();

		while ($user = $result->fetch())
		{
			$id = (int)($user['ID'] ?? 0);

			$externalAuthId = $user['EXTERNAL_AUTH_ID'] ?? null;
			if ($botExternalAuthId !== null && $externalAuthId === $botExternalAuthId)
			{
				$botIds[] = $id;

				continue;
			}

			$employeeIds[] = $id;
		}

		$guestIds = array_values(array_diff($userIds, $employeeIds, $botIds));

		return [$employeeIds, $guestIds, $botIds];
	}
}
