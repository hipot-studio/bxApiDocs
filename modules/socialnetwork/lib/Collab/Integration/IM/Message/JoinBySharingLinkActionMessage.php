<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Feature;

class JoinBySharingLinkActionMessage implements ActionMessageInterface
{
	use MessageTrait;

	protected int $collabId;
	protected int $senderId;

	public function __construct(int $collabId, int $senderId)
	{
		$this->collabId = $collabId;
		$this->senderId = $senderId;
	}

	public function send(array $recipientIds = [], array $parameters = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		if (empty($recipientIds))
		{
			return 0;
		}

		$this->addUsersToChat($this->collabId, $parameters, ...$recipientIds);

		$authorId = (int)($parameters['authorId'] ?? 0);

		$lastMessageId = 0;
		foreach ($recipientIds as $recipientId)
		{
			$recipientName = $this->getName($this->senderId, $recipientId, $this->collabId);

			$authorName = $authorId > 0
				? $this->getName($this->senderId, $authorId, $this->collabId)
				: '';

			$phraseCode = Feature::isNewProjectsOn()
				? 'SOCIALNETWORK_V2_PROJECT_CHAT_JOIN_BY_SHARING_LINK'
				: 'SOCIALNETWORK_COLLAB_CHAT_JOIN_BY_SHARING_LINK'
			;

			$message = (string)Loc::getMessage(
				$phraseCode . $this->getGenderSuffix($recipientId),
				[
					'#RECIPIENT#' => $recipientName,
					'#AUTHOR_NAME#' => $authorName,
				],
			);

			$lastMessageId = $this->sendMessage(
				message: $message,
				senderId: $recipientId,
				groupId: $this->collabId,
				silent: Feature::isNewProjectsOn() ? self::SILENT_WITH_RECENT : self::SILENT_OFF,
			);
		}

		return $lastMessageId;
	}
}
