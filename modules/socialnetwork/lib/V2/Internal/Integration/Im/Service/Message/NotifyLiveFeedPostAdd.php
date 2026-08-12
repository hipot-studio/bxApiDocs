<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Gender;

class NotifyLiveFeedPostAdd implements MessageDataInterface
{
	private ?string $message = null;

	public function __construct(
		private readonly ?User $contextUser,
		private readonly string $url,
		private readonly int $logId = 0,
	)
	{
	}

	public function getLogId(): int
	{
		return $this->logId;
	}

	public function getText(): string
	{
		if ($this->message === null)
		{
			$locKey = match($this->contextUser?->gender)
			{
				Gender::Female => 'SONET_V2_NOTIFY_LIVE_FEED_NEW_POST_F',
				Gender::Male => 'SONET_V2_NOTIFY_LIVE_FEED_NEW_POST_M',
				default => 'SONET_V2_NOTIFY_LIVE_FEED_NEW_POST_N',
			};

			$message = Loc::GetMessage($locKey, [
				'#USER_NAME#' => (string)$this->contextUser?->name,
				'#LINK#' => $this->url,
			]);

			$this->message = $message ?? '';
		}

		return $this->message;
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
