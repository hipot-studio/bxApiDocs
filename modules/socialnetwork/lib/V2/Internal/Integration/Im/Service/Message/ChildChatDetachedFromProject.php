<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Gender;

class ChildChatDetachedFromProject implements MessageDataInterface
{
	public function __construct(
		private readonly ?User $contextUser,
		private readonly string $projectName,
	)
	{
	}

	public function getText(): string
	{
		$locKey = match($this->contextUser?->gender)
		{
			Gender::Female => 'SONET_V2_CHILD_CHAT_DETACHED_FROM_PROJECT_F',
			Gender::Male => 'SONET_V2_CHILD_CHAT_DETACHED_FROM_PROJECT_M',
			default => 'SONET_V2_CHILD_CHAT_DETACHED_FROM_PROJECT_N',
		};

		$message = Loc::getMessage($locKey, [
			'#USER_NAME#' => "[USER={$this->contextUser?->id}][/USER]",
			'#PROJECT_NAME#' => $this->projectName,
		]);

		return $message ?? '';
	}

	public function getContextUserId(): int
	{
		return $this->contextUser?->id ?? 0;
	}

	public function getAuthorId(): int
	{
		return 0;
	}
}
