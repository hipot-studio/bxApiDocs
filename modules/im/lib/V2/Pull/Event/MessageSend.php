<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePointerReader;

class MessageSend extends BaseMessageEvent
{
	private const MAX_COUNTER = 100;

	protected SendingConfig $sendingConfig;
	protected UsersCounterMap $counters;

	private ?CollabPreviewSourcePointerReader $collabPreviewSourcePointerReader = null;

	/** @var int[]|null */
	private ?array $collabPreviewSourceCandidateUserIds = null;

	public function __construct(Message $message, SendingConfig $config, UsersCounterMap $counters)
	{
		$this->sendingConfig = $config;
		$this->counters = $counters;
		parent::__construct($message);
	}

	protected function getBasePullParamsInternal(): array
	{
		return (new Message\PushFormat($this->message))->format();
	}

	public function getPullForPublic(): array
	{
		$pull = parent::getPullForPublic();
		$pull['params']['message']['params']['NOTIFY'] = 'N';
		$pull['extra']['is_shared_event'] = true;
		$pull['params']['recentConfig']['sections'] = $this->chat->getRecentSectionsForGuest();

		return $pull;
	}

	protected function getType(): EventType
	{
		return $this->chat instanceof PrivateChat ? EventType::PrivateMessageSend : EventType::MessageSend;
	}

	protected function getRecipients(): array
	{
		return $this->chat->getPullRecipients()->getUserIds();
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return false;
	}

	protected function getDiffByUser(int $userId): Diff
	{
		$diffParams = [];

		if (!$this->shouldRecentAddToUser($userId))
		{
			$diffParams['counter'] = 0;
			$diffParams['recentConfig']['sections'] = [];
			$diffParams['notify'] = false;
			$diffParams['message']['importantFor'] = [];
			$diffParams['message']['isImportant'] = false;
		}
		else
		{
			$diffParams['counter'] = $this->getCounter($userId);
			if ($this->shouldSkipRecentUpdate($userId))
			{
				$diffParams['skipRecentUpdate'] = true;
			}
		}

		$diffParams['dialogId'] = $this->chat->getDialogId($userId);

		return new Diff($userId, $diffParams);
	}

	private function shouldRecentAddToUser(int $userId): bool
	{
		if (!$this->sendingConfig->addRecent())
		{
			return false;
		}

		$relation = $this->chat->getRelationByUserId($userId);
		if ($relation?->isHidden())
		{
			return false;
		}

		if (
			$this->sendingConfig->skipAuthorAddRecent()
			&& $this->message->getAuthorId() === $userId
		)
		{
			return false;
		}

		return true;
	}

	private function shouldSkipRecentUpdate(int $userId): bool
	{
		if (!$this->canSkipRecentUpdate())
		{
			return false;
		}

		$relation = $this->chat->getRelationByUserId($userId);
		if ($relation === null || $relation->getNotifyBlock() !== true)
		{
			return false;
		}

		$pointer = $this->getCollabPreviewSourcePointerReader()->read()[$userId] ?? null;
		if ($pointer === null)
		{
			return false;
		}

		return $pointer['sourceChatId'] > 0 && $pointer['messageId'] > 0;
	}

	private function canSkipRecentUpdate(): bool
	{
		return Features::isCollabPreviewSourceEnabled()
			&& $this->chat instanceof CollabChat
			&& !$this->chat->hasParent()
		;
	}

	private function getCollabPreviewSourcePointerReader(): CollabPreviewSourcePointerReader
	{
		$this->collabPreviewSourcePointerReader ??= new CollabPreviewSourcePointerReader(
			(int)$this->chat->getId(),
			$this->getCollabPreviewSourceCandidateUserIds(),
		);

		return $this->collabPreviewSourcePointerReader;
	}

	/**
	 * @return int[]
	 */
	private function getCollabPreviewSourceCandidateUserIds(): array
	{
		if ($this->collabPreviewSourceCandidateUserIds !== null)
		{
			return $this->collabPreviewSourceCandidateUserIds;
		}

		$this->collabPreviewSourceCandidateUserIds = [];
		foreach ($this->getRecipients() as $userId)
		{
			$userId = (int)$userId;
			if (!$this->shouldRecentAddToUser($userId))
			{
				continue;
			}

			$relation = $this->chat->getRelationByUserId($userId);
			if ($relation !== null && $relation->getNotifyBlock() === true)
			{
				$this->collabPreviewSourceCandidateUserIds[$userId] = $userId;
			}
		}

		return array_values($this->collabPreviewSourceCandidateUserIds);
	}

	private function getCounter(int $userId): int
	{
		return min($this->counters[$userId] ?? 0, self::MAX_COUNTER);
	}

/*	public function shouldSendMobilePush(): bool
	{
		return $this->sendingConfig->sendPush() && !$this->message->isSystem();
	}*/
}
