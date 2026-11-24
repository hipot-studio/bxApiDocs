<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatTreeScope;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Pull\Event\BaseChatEvent;
use Bitrix\Im\V2\Pull\EventType;

class ReadChildren extends BaseChatEvent
{
	public function __construct(
		protected Chat $chat,
		protected int $userId,
		protected ChatTreeScope $scope,
	) {
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		$params = [
			'chatId' => $this->chat->getChatId(),
		];

		$chatTypes = $this->getScopeChatTypes();
		if ($chatTypes !== null)
		{
			$params['chatTypes'] = $chatTypes;
		}

		return $params;
	}

	/**
	 * The types the clearing was limited to, so a client clears the children it names and no others: the
	 * event is sent from two scopes, the threads of a parent and its whole subtree, and by the id of the
	 * parent alone they are indistinguishable.
	 *
	 * The scope is projected and not labelled: an include list of a type condition is already the exact
	 * set of admitted types, exclusions subtracted, so the answer cannot drift from what was cleared. A
	 * scope narrowed by exclusions alone names no such set and is reported as no limit at all, which is
	 * the behaviour clients have today; neither of the two senders builds one.
	 *
	 * @return string[]|null null when the clearing was not limited to a nameable set of types
	 */
	private function getScopeChatTypes(): ?array
	{
		$includedTypes = $this->scope->typeCondition?->include;

		if ($includedTypes === null)
		{
			return null;
		}

		return array_values(array_map(
			static fn(Type $type): string => $type->getExtendedType(),
			$includedTypes,
		));
	}

	protected function getType(): EventType
	{
		return EventType::ReadChildren;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}
}
