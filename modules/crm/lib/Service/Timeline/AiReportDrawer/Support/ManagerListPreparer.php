<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Main\Type\Collection;

final class ManagerListPreparer
{
	public static function prepare(array $managers): array
	{
		$managersById = [];
		foreach ($managers as $manager)
		{
			$managerId = (int)($manager['responsibleId'] ?? 0);
			if ($managerId <= 0)
			{
				continue;
			}

			$workedAtTs = (int)($manager['workedAtTs'] ?? 0);
			if (
				isset($managersById[$managerId])
				&& (int)($managersById[$managerId]['workedAtTs'] ?? 0) >= $workedAtTs
			)
			{
				continue;
			}

			$managersById[$managerId] = $manager;
			$managersById[$managerId]['responsibleId'] = $managerId;
			$managersById[$managerId]['workedAtTs'] = $workedAtTs;
		}

		Collection::sortByColumn($managersById, [
			'workedAtTs' => SORT_DESC,
			'responsibleName' => SORT_ASC,
		]);

		return array_map(
			static function (array $manager): array {
				unset($manager['workedAtTs']);

				return $manager;
			},
			$managersById,
		);
	}
}
