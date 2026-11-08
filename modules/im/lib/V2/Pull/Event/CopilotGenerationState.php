<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Copilot\GenerationStatus;
use Bitrix\Im\V2\Pull\EventType;

/**
 * Broadcasts the copilot generation state to chat members.
 *
 * The initiatorId carries the user this state belongs to, but its exact meaning is
 * status-dependent:
 *  - Stopped: the user who pressed stop;
 *  - Generating / Completed / Error: the owner of the generation session (the user whose
 *    turn is being generated).
 * In a one-to-one copilot chat these are always the same user, which is why a single field
 * is enough; the distinction matters only if this event is ever reused for a multi-user chat.
 */
class CopilotGenerationState extends BaseChatEvent
{
	use DialogIdFiller;

	protected GenerationStatus $status;

	/** @see CopilotGenerationState class doc — meaning is status-dependent. */
	protected int $initiatorId;

	public function __construct(Chat $chat, GenerationStatus $status, int $initiatorId)
	{
		parent::__construct($chat);
		$this->status = $status;
		$this->initiatorId = $initiatorId;
	}

	protected function getType(): EventType
	{
		return EventType::CopilotGenerationState;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'dialogId' => $this->getBaseDialogId(),
			'chatId' => $this->chat->getId(),
			'status' => $this->status->value,
			'initiatorId' => $this->initiatorId,
		];
	}

	protected function getRecipients(): array
	{
		return $this->chat->getRelations()->filterActive()->getUserIds();
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return false;
	}
}
