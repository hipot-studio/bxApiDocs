<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\Provider;

use Bitrix\Main\Loader;

if (!Loader::includeModule('intranet'))
{
	return;
}

class LegacyProjectsAndCollabsSummaryProvider extends AbstractAnnualSummaryProvider
{
	private const ID = 'socialnetwork.created_legacy_projects_and_collabs';
	private const USER_ID_LIMIT = 500;

	public function getId(): string
	{
		return self::ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	protected function countByUserIds(array $userIds): array
	{
		return $this->repository->countLegacyContainers($userIds, $this->from, $this->to);
	}
}
