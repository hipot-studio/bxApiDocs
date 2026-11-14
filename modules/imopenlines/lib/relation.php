<?php
namespace Bitrix\ImOpenLines;

use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Pull\Event\ChatHide;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\ImOpenLines\Model\RecentTable;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\Main\Loader;
use Bitrix\Pull\Event;

class Relation
{
	private int $chatId;
	private array $userIds = [];
	private ?array $line = null;
	private int $userId;

	public function __construct(int $chatId)
	{
		$this->chatId = $chatId;
		$this->preloadFakeRelations($this->chatId);
	}

	public function addRelation(int $userId): void
	{
		$this->userId = $userId;
		$this->userIds = array_unique(array_merge($this->userIds, [$this->userId]));

		$chat = ChatFactory::getInstance()->getChatById($this->chatId);
		$session = SessionTable::getRow([
			'filter' => [
				'=CHAT_ID' => $this->chatId
			]
		]);
		Recent::setRecent($this->userId, $this->chatId, $chat->getLastMessageId(), $session['ID']);

		$this->sendPullAdd();
	}

	public function removeRelation(int $userId): void
	{
		$this->userId = $userId;
		$this->userIds = array_unique(array_diff($this->userIds, [$this->userId]));

		$this->sendPullChatHide();

		Recent::removeRecent($this->userId, $this->chatId);
	}

	public function removeAllRelations(bool $force = false, array $excludeUserIds = []): void
	{
		$this->userIds = array_diff($this->userIds, $excludeUserIds);
		foreach ($this->userIds as $userId)
		{
			if ($force || !isset($this->userId) || $this->userId !== $userId)
			{
				$this->removeRelation($userId);
			}
		}
	}

	private function sendPullAdd(): bool
	{
		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		$pushMessage = $this->getPullAddMessage($this->userId);

		Event::add([$this->userId], $pushMessage);
		return \CPullWatch::AddToStack('IM_PUBLIC_' . $this->chatId, $pushMessage);
	}

	/**
	 * Chat state follows the convention of \Bitrix\Im\V2\Pull\Event\ChatUserAdd: a user without a real
	 * relation gets a synthetic one (guest role), and the chat owner is the actual author of the chat.
	 *
	 * @internal
	 */
	public function getPullAddMessage(int $userId): array
	{
		$users = \CIMContactList::GetUserData([
			'ID' => [$userId],
			'DEPARTMENT' => 'N',
			'USE_CACHE' => 'N'
		]);
		$chat = \Bitrix\Im\V2\Chat::getInstance($this->chatId);

		$relations = $chat->getRelationsByUserIds([$userId]);
		if ($relations->count() === 0)
		{
			$relations = RelationCollection::createFake([$userId], $chat);
		}

		$participantIds = array_unique(array_merge($this->userIds, [$userId]));

		return [
			'module_id' => 'im',
			'command' => 'chatUserAdd',
			'params' => [
				'chatId' => $this->chatId,
				'dialogId' => 'chat' . $this->chatId,
				'chatTitle' => '',
				'chatOwner' => (int)$chat->getAuthorId(),
				'chatExtranet' => 'N',
				'containsCollaber' => false,
				'users' => $users['users'],
				'newUsers' => [$userId],
				// toRestFormat() is declared nullable, and the console calls relations.find() on this
				// without a guard, so the key stays an array whatever the collection returns.
				'relations' => $relations->toRestFormat() ?? [],
				'userCount' => count($participantIds),
				'date' => date('H:i:s'),
				'lines' => $this->getLine()
			],
			'extra' => \Bitrix\Im\Common::getPullExtra()
		];
	}

	private function sendPullChatHide(): bool
	{
		$userIds = array_unique(array_merge($this->userIds, [$this->userId]));
		$chat = \Bitrix\Im\V2\Chat::getInstance($this->chatId);
		$result = (new ChatHide($chat, $userIds))->send();

		return $result->isSuccess();
	}

	private function getLine(): ?array
	{
		if (is_array($this->line))
		{
			return $this->line;
		}

		$session = SessionTable::getRow([
			'select' => ['ID', 'STATUS', 'DATE_CREATE'],
			'filter' => [
				'=CHAT_ID' => $this->chatId,
			]
		]);
		if (is_null($session))
		{
			return null;
		}

		$this->line = [
			'id' => (int)$session['ID'],
			'status' => (int)$session['STATUS'],
			'date_create' => $session['DATE_CREATE'],
		];

		return $this->line;
	}

	private function preloadFakeRelations(int $chatId): void
	{
		$relations = RecentTable::getList([
			'select' => ['USER_ID'],
			'filter' => [
				'=CHAT_ID' => $chatId
			]
		]);

		foreach ($relations->fetchAll() as $relation)
		{
			$this->userIds[] = (int)$relation['USER_ID'];
		}
	}

	public function getRelationUserIds(): array
	{
		return $this->userIds;
	}
}
