<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Repository;

interface ChatMemberRepositoryInterface
{
	/**
	 * @return int[]
	 */
	public function getMemberUserIds(int $chatId): array;

	/**
	 * @return int[]
	 */
	public function getManagerUserIds(int $chatId): array;
}
