<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Workgroup;

use Bitrix\Socialnetwork\Component\WorkgroupList;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\AbstractPresetFilter;

class UserWorkgroupFilter extends AbstractPresetFilter
{
	public const FILTER_ID = 'SONET_GROUP_LIST_USER';

	public function __construct(
		int $currentUserId,
		private readonly int $contextUserId = 0,
		string $idSuffix = '',
		string $extranetSiteId = '',
		string $customId = '',
	)
	{
		parent::__construct(
			currentUserId: $currentUserId,
			idSuffix: $idSuffix,
			extranetSiteId: $extranetSiteId,
			customId: $customId,
		);
	}

	protected function getMode(): string
	{
		return WorkgroupList::MODE_USER;
	}

	protected function getContextUserId(): int
	{
		return $this->contextUserId;
	}
}
