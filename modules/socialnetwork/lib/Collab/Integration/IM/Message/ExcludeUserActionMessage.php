<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\V2\Feature;

class ExcludeUserActionMessage implements ActionMessageInterface
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

		$recipientNames = [];
		foreach ($recipientIds as $recipientId)
		{
			$recipientNames[] = $this->getName($this->senderId, $recipientId, $this->collabId);
		}

		$this->deleteUsersFromChat($this->collabId, ...$recipientIds);

		$userNames = implode(', ', $recipientNames);
		$senderName = $this->getName($this->senderId, $this->senderId, $this->collabId);

		$isNewProjectsOn = Feature::isNewProjectsOn();

		$phraseCode = $isNewProjectsOn
			? 'SOCIALNETWORK_V2_PROJECT_CHAT_USER_EXCLUDE'
			: 'SOCIALNETWORK_COLLAB_CHAT_USER_EXCLUDE'
		;

		$message = (string)Loc::getMessage(
			$phraseCode . $this->getGenderSuffix($this->senderId),
			[
				'#SENDER_NAME#' => $senderName,
				'#RECIPIENT#' => $userNames,
			],
		);

		return $this->sendMessage(
			message: $message,
			senderId: $this->senderId,
			groupId: $this->collabId,
			silent: $this->resolveCounterSilent(ActionType::ExcludeUser, $this->collabId, $isNewProjectsOn ? self::SILENT_WITH_RECENT : self::SILENT_OFF),
		);
	}
}