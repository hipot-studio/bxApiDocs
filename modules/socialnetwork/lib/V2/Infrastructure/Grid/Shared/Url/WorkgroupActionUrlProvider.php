<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url;

class WorkgroupActionUrlProvider
{
	private const GROUP_ID_TOKENS = ['#id#', '#ID#', '#GROUP_ID#', '#group_id#'];

	public function __construct(
		private readonly string $pathToGroupEdit,
		private readonly string $pathToGroupDelete,
		private readonly string $pathToLeaveGroup,
	)
	{
	}

	public function getEditUrl(int $groupId): string
	{
		return $this->replaceGroupId($this->pathToGroupEdit, $groupId);
	}

	public function getDeleteUrl(int $groupId): string
	{
		return $this->replaceGroupId($this->pathToGroupDelete, $groupId);
	}

	public function getLeaveUrl(int $groupId): string
	{
		return $this->replaceGroupId($this->pathToLeaveGroup, $groupId);
	}

	private function replaceGroupId(string $path, int $groupId): string
	{
		if ($groupId <= 0 || $path === '')
		{
			return '';
		}

		return str_replace(self::GROUP_ID_TOKENS, (string)$groupId, $path);
	}
}
