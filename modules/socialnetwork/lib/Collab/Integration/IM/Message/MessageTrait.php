<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Im\Bot;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\Relation\Reason;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Socialnetwork\Collab\Collab;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ShowHistoryOption;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Collab\Integration\Intranet\ServiceContainer;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\Notification\CounterDecision;
use Bitrix\Socialnetwork\V2\Internal\Service\Notification\CounterPolicyResolver;
use CAllSocNetUser;
use CIMChat;
use CUser;

trait MessageTrait
{
	private const SILENT_OFF = 0;
	private const SILENT_WITH_RECENT = 1;
	private const SILENT_WITHOUT_RECENT = 2;
	private const CONTEXT_USER = 0;

	protected static array $users = [];

	protected function getCollab(int $collabId): Collab
	{
		$collab = CollabRegistry::getInstance()->get($collabId);
		if ($collab === null)
		{
			throw new ObjectNotFoundException('Collab not found');
		}

		return $collab;
	}

	protected function getWorkgroup(int $groupId): Workgroup
	{
		$workgroup = GroupRegistry::getInstance()->get($groupId);
		if ($workgroup === null)
		{
			throw new ObjectNotFoundException('Workgroup not found');
		}

		return $workgroup;
	}

	protected function getChat(): CIMChat
	{
		return new CIMChat(self::CONTEXT_USER);
	}

	protected function getName(int $senderId, int $recipientId, int $collabId): string
	{
		$recipientBbCode = "[USER={$recipientId}][/USER]";

		if ($senderId === $recipientId)
		{
			return $recipientBbCode;
		}

		$workgroup = $this->getWorkgroup($collabId);

		foreach ($workgroup->getSiteIds() as $siteId)
		{
			if (CAllSocNetUser::CanProfileView($this->senderId, $recipientId, $siteId))
			{
				return $recipientBbCode;
			}
		}

		if ($this->isBot($recipientId))
		{
			return $recipientBbCode;
		}

		$inviteService = ServiceContainer::getInstance()?->getUserService();

		$names = $inviteService?->getFormattedInvitationNameByIds([$recipientId]);

		return $names[$recipientId] ?? '';
	}

	protected function isBot(int $userId): bool
	{
		$user = $this->getUser($userId);

		$externalAuthId = $user['EXTERNAL_AUTH_ID'] ?? null;

		return $externalAuthId === Bot::EXTERNAL_AUTH_ID;
	}

	protected function getUser(int $id): array
	{
		if (!isset(static::$users[$id]))
		{
			$user = CUser::getById($id)->fetch();
			static::$users[$id] = is_array($user) ? $user : [];
		}

		return static::$users[$id];
	}

	protected function getGenderSuffix(int $userId): string
	{
		return match ($this->getGender($userId))
		{
			'F' => '_F',
			'M' => '_M',
			default => '_N',
		};
	}

	protected function getGender(int $userId): string
	{
		$user = $this->getUser($userId);

		return $user['PERSONAL_GENDER'] ?? '';
	}

	protected function addUsersToChat(int $collabId, array $parameters, int ...$userIds): void
	{
		$collab = $this->getCollab($collabId);

		$initiatedByType = $parameters['initiatedByType'] ?? UserToGroupTable::INITIATED_BY_GROUP;
		$isInitiatedByStructure = $initiatedByType === UserToGroupTable::INITIATED_BY_STRUCTURE;

		$config = new AddUsersConfig(
			hideHistory: $collab->getOptionValue(ShowHistoryOption::DB_NAME) !== 'Y',
			withMessage: false,
			skipRecent: true,
			reason: $isInitiatedByStructure ? Reason::STRUCTURE : Reason::DEFAULT,
			skipAnalytics: false,
		);

		Chat::getInstance($collab->getChatId())
			->withContextUser(self::CONTEXT_USER)
			->addUsers($userIds, $config)
		;
	}

	protected function deleteUsersFromChat(int $collabId, int ...$userIds): void
	{
		$collab = $this->getCollab($collabId);

		$config = new DeleteUserConfig(withMessage: false, skipCheckReason: true);

		$chat = Chat::getInstance($collab->getChatId())->withContextUser(self::CONTEXT_USER);

		if (!$chat instanceof Chat\CollabChat)
		{
			return;
		}

		foreach ($userIds as $recipientId)
		{
			$chat->deleteUser($recipientId, $config);
		}
	}

	/**
	 * Resolves the counter decision from the policy resolver and translates it
	 * into a SILENT_* constant. Passthrough preserves the given $passthroughSilent value.
	 */
	protected function resolveCounterSilent(ActionType $actionType, int $projectId, int $passthroughSilent): int
	{
		try
		{
			$decision = Container::getInstance()->get(CounterPolicyResolver::class)->resolveByActionType($actionType, $projectId);
		}
		catch (\Throwable)
		{
			return $passthroughSilent;
		}

		return match ($decision)
		{
			CounterDecision::CounterOn => self::SILENT_OFF,
			CounterDecision::CounterOff => self::SILENT_WITH_RECENT,
			CounterDecision::Passthrough => $passthroughSilent,
		};
	}

	protected function sendMessage(
		string $message,
		int $senderId,
		int $groupId,
		?string $componentId = null,
		int $silent = self::SILENT_OFF,
	): int
	{
		$fields = [
			'MESSAGE' => $message,
			'SYSTEM' => 'Y',
			'PUSH' => 'N',
			'FROM_USER_ID' => $senderId,
			'SKIP_USER_CHECK' => 'Y',
			'TO_CHAT_ID' => $this->getWorkgroup($groupId)->getChatId(),
			'PARAMS' => [
				'NOTIFY' => 'N',
			],
		];

		$silentOptions = $this->getSilentOptions($silent);
		if ($silentOptions)
		{
			$fields = array_merge($fields, $silentOptions);
		}

		if ($componentId)
		{
			$fields['PARAMS']['COMPONENT_ID'] = $componentId;
		}

		$chat = $this->getChat();

		return (int)$chat::addMessage($fields);
	}

	private function getSilentOptions(int $silent): array
	{
		return match ($silent)
		{
			self::SILENT_WITH_RECENT => [
				'SKIP_COUNTER_INCREMENTS' => 'Y',
			],
			self::SILENT_WITHOUT_RECENT => [
				'SKIP_COUNTER_INCREMENTS' => 'Y',
				'RECENT_ADD' => 'N',
			],
			default => [],
		};
	}
}
