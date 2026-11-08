<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Collab\Integration\Intranet\Invitation;
use Bitrix\Socialnetwork\V2\Feature;

class AcceptUserActionMessage implements ActionMessageInterface
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

		$this->addUsersToChat($this->collabId, $parameters, $this->senderId);

		$this->sendAcceptUserAnalytics();

		// Accepting a user is more than the sentence it puts in the chat: suppressing the announcement
		// must leave the chat membership and the first initialization above it untouched.
		if ($parameters[ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER] ?? false)
		{
			return 0;
		}

		$isNewProjectsOn = Feature::isNewProjectsOn();

		$phraseCode = $isNewProjectsOn
			? 'SOCIALNETWORK_V2_PROJECT_CHAT_USER_ACCEPT'
			: 'SOCIALNETWORK_COLLAB_CHAT_USER_ACCEPT'
		;

		$message = (string)Loc::getMessage(
			$phraseCode . $this->getGenderSuffix($this->senderId),
			[
				'#SENDER_NAME#' => $this->getName($this->senderId, $this->senderId, $this->collabId),
			],
		);

		return $this->sendMessage(
			message: $message,
			senderId: $this->senderId,
			groupId: $this->collabId,
			silent: $this->resolveCounterSilent(ActionType::AcceptUser, $this->collabId, $isNewProjectsOn ? self::SILENT_WITH_RECENT : self::SILENT_OFF),
		);
	}

	private function sendAcceptUserAnalytics(): void
	{
		$fields = Invitation::getFields($this->senderId);

		if ($fields === null)
		{
			return;
		}

		$event = new Event('intranet', 'onUserFirstInitialization', [
			'invitationFields' => $fields,
			'userId' => $this->senderId,
		]);

		$event->send();
	}
}
