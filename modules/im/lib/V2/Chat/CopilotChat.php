<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Param\Params as ChatParams;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Integration\AI\EngineManager;
use Bitrix\Im\V2\Integration\AI\AIHelper;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Message\Send\SendResult;
use Bitrix\ImBot\Bot;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Im\V2\Chat\Add\AddResult;

class CopilotChat extends GroupChat
{
	private const COUNTER_CHAT_CODE = 'copilot_chat_counter';
	private const COPILOT_ROLE_UPDATED = 'COPILOT_ROLE_UPDATED';

	public function __construct($source = null, ?Context $context = null)
	{
		Loader::includeModule('imbot');

		parent::__construct($source, $context);
	}

	protected function getDefaultType(): string
	{
		return self::IM_TYPE_COPILOT;
	}

	public function getDefaultManageUsersAdd(): string
	{
		return self::MANAGE_RIGHTS_MEMBER;
	}

	public function getDefaultManageUsersDelete(): string
	{
		return self::MANAGE_RIGHTS_MEMBER;
	}

	public function getDefaultManageSettings(): string
	{
		return self::MANAGE_RIGHTS_NONE;
	}

	public function getManageUsersAdd(): ?string
	{
		return $this::MANAGE_RIGHTS_MEMBER;
	}

	public function getManageUsersDelete(): ?string
	{
		return $this::MANAGE_RIGHTS_MEMBER;
	}

	public function setManageMessages(string $manageMessages): Chat
	{
		return $this;
	}

	public function setManageSettings(string $manageSettings): Chat
	{
		return $this;
	}

	public function setManageUI(string $manageUI): Chat
	{
		return $this;
	}

	public function setManageUsersAdd(string $manageUsersAdd): Chat
	{
		return $this;
	}

	public function setManageUsersDelete(string $manageUsersDelete): Chat
	{
		return $this;
	}

	public function add(array $params, ?Context $context = null): AddResult
	{
		$result = new AddResult();

		$availability = self::checkCopilotAvailability();
		if (!$availability->isSuccess())
		{
			return $result->addErrors($availability->getErrors());
		}

		$copilotBotId = AIHelper::getCopilotBotId();

		if (isset($params['USERS']))
		{
			if (!in_array($copilotBotId, $params['USERS'], true))
			{
				$params['USERS'][] = $copilotBotId;
			}
		}
		else
		{
			$context ??= new Context();
			$params['USERS'] = [$context->getUserId(), $copilotBotId];
		}

		return parent::add($params, $context);
	}

	protected function getValidUsersToAdd(array $userIds): array
	{
		$filterUserIds = parent::getValidUsersToAdd($userIds);
		$copilotChatBotId = Loader::includeModule('imbot') ? CopilotChatBot::getBotId() : 0;

		if ($copilotChatBotId <= 0 || !in_array($copilotChatBotId, $userIds, true))
		{
			return $filterUserIds;
		}

		// Keyed by user id as everywhere in the members composition: the bot is taken out of the
		// invitation message by key.
		$filterUserIds[$copilotChatBotId] = $copilotChatBotId;

		return $filterUserIds;
	}

	public function addUsers(array $userIds, AddUsersConfig $config = new AddUsersConfig()): Chat
	{
		if (empty($userIds) || !$this->getChatId())
		{
			return $this;
		}

		$usersToAdd = $this->getUsersWithoutBots($userIds);

		return parent::addUsers($usersToAdd, $config);
	}

	protected function getUsersWithoutBots(array $userIds): array
	{
		$usersToAdd = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;

			$user = Im\V2\Entity\User\User::getInstance($userId);
			if ($user->isExist() && $user->isActive() && !$user->isBot())
			{
				$usersToAdd[$userId] = $userId;
			}
		}

		return $usersToAdd;
	}

	protected function sendMessageUsersAdd(array $usersToAdd, AddUsersConfig $config): void
	{
		if (empty($usersToAdd))
		{
			return;
		}

		$oldUsers = array_diff($this->getRelations()->getUserIds(), $usersToAdd);
		if (count($oldUsers) === 2)
		{
			$this->sendAddedUsersBanner();
			return;
		}

		if (in_array(Bot\CopilotChatBot::getBotId(), $usersToAdd, true))
		{
			unset($usersToAdd[Bot\CopilotChatBot::getBotId()]);
		}

		parent::sendMessageUsersAdd($usersToAdd, $config);
	}

	protected function sendGreetingMessage(?int $authorId = null)
	{
		return;
	}

	public function sendBanner(?int $authorId = null, bool $force = false, ?string $copilotName = null, bool $isUpdate = false): void
	{
		if (!$authorId)
		{
			$authorId = $this->getAuthorId();
		}

		// The chat can be created in a system context, which names no copilot: the name comes from the author.
		$roleManager = (new Im\V2\Integration\AI\RoleManager())->setContextUser($authorId);
		$copilotCode = $roleManager->getValidRoleCode($roleManager->getMainRole($this->getChatId()));
		$defaultRoleCode = Im\V2\Integration\AI\RoleManager::getDefaultRoleCode();

		if (
			!$isUpdate
			&& !$force
			&& Features::get()->isBitrixGptV2Available
			&& Features::get()->isCopilotDraftChatAvailable
			&& $defaultRoleCode !== null
			&& $copilotCode === $defaultRoleCode
			&& !($this->hasParent() && $this->getParentChat() instanceof CollabChat)
		)
		{
			return;
		}

		if (!isset($copilotName))
		{
			$copilotName = $roleManager->getRoles([$copilotCode])[$copilotCode]['name'] ?? '';
		}

		\CIMMessage::Add([
			'MESSAGE_TYPE' => $this->getType(),
			'TO_CHAT_ID' => $this->getChatId(),
			'FROM_USER_ID' => Bot\CopilotChatBot::getBotId(),
			"SYSTEM" => 'Y',
			'MESSAGE' => $isUpdate
				? Loc::getMessage('IM_CHAT_CREATE_COPILOT_WELCOME_UPDATE', ['#COPILOT_NAME#' => $copilotName])
				: Loc::getMessage('IM_CHAT_CREATE_COPILOT_WELCOME_CREATE', ['#COPILOT_NAME#' => $copilotName])
			,
			'SKIP_USER_CHECK' => 'Y',
			'PUSH' => 'N',
			'SKIP_COUNTER_INCREMENTS' => 'Y',
			'PARAMS' => [
				Params::COMPONENT_ID => Bot\CopilotChatBot::MESSAGE_COMPONENT_START,
				Params::COMPONENT_PARAMS => [self::COPILOT_ROLE_UPDATED => $isUpdate],
				Params::NOTIFY => 'N',
				Params::COPILOT_ROLE => $copilotCode,
			]
		]);
	}

	public function sendAddedUsersBanner(): void
	{
		$author = $this->getAuthor();
		$addedUsers = $this->getRelations()->getUserIds();
		unset($addedUsers[$author->getId()], $addedUsers[Bot\CopilotChatBot::getBotId()]);

		if (empty($addedUsers))
		{
			return;
		}

		\CIMMessage::Add([
			'MESSAGE_TYPE' => $this->getType(),
			'TO_CHAT_ID' => $this->getChatId(),
			'FROM_USER_ID' => Bot\CopilotChatBot::getBotId(),
			"SYSTEM" => 'Y',
			'MESSAGE' => Loc::getMessage(
				"IM_CHAT_CREATE_COPILOT_COLLECTIVE_{$author->getGender()}_MSGVER_1",
				[
					'#USER_1_NAME#' => htmlspecialcharsback($author->getName()),
					'#USER_2_NAME#' => $this->getUsersForBanner($addedUsers)
				],
			),
			'PUSH' => 'N',
			'PARAMS' => [
				Params::COMPONENT_ID => Bot\CopilotChatBot::MESSAGE_COMPONENT_COLLECTIVE,
				Params::NOTIFY => 'N',
				Params::COMPONENT_PARAMS => [
					'AUTHOR_ID' => $author->getId(),
					'ADDED_USERS' => array_values($addedUsers),
				],
				Params::COPILOT_ROLE => (new Im\V2\Integration\AI\RoleManager())->getMainRole($this->getId()),
			],
		]);
	}

	public function containsCopilot(): bool
	{
		return true;
	}

	public static function activateDraftIfNeeded(Chat $chat): void
	{
		if ($chat instanceof self)
		{
			$chat->activateDraftChat();
		}
	}

	public function isDraftChat(): bool
	{
		return $this->getChatParams()->get(ChatParams::IS_COPILOT_DRAFT) !== null;
	}

	public function activateDraftChat(): void
	{
		if (!$this->isDraftChat())
		{
			return;
		}

		$this->getChatParams()->deleteParam(ChatParams::IS_COPILOT_DRAFT);
		$this->addIndex();
	}

	public function sendMessage(Im\V2\Message $message, ?SendingConfig $sendingConfig = null): SendResult
	{
		$result = parent::sendMessage($message, $sendingConfig);

		if ($result->isSuccess() && $result->getMessageId())
		{
			$this->activateDraftChat();
		}

		return $result;
	}

	protected function addIndex(): self
	{
		if ($this->isDraftChat())
		{
			return $this;
		}

		return parent::addIndex();
	}

	private function getUsersForBanner(array $addedUsers): string
	{
		$userCodes = [];
		foreach ($addedUsers as $userId)
		{
			$userCodes[] = "[USER={$userId}][/USER]";
		}

		return implode(', ', $userCodes);
	}

	protected function sendDescriptionMessage(?int $authorId = null): void
	{
		return;
	}

	public function getDescription(): ?string
	{
		return null;
	}

	public static function getTitleTemplate(): ?string
	{
		return Loc::getMessage('IM_CHAT_COPILOT_CHAT_TITLE');
	}

	protected function generateTitle(): string
	{
		// The chat can be created in a system context, which owns no counter: the number is the author's.
		$counterOwnerId = $this->getContext()->getUserId() ?: (int)$this->getAuthorId();
		$copilotChatCounter = \CUserOptions::GetOption('im', self::COUNTER_CHAT_CODE, 1, $counterOwnerId);
		$title = Loc::getMessage('IM_CHAT_COPILOT_CHAT_TITLE', ['#NUMBER#' => $copilotChatCounter]);
		\CUserOptions::SetOption('im', self::COUNTER_CHAT_CODE, $copilotChatCounter + 1, false, $counterOwnerId);

		return $title;
	}

	protected function sendEventUsersAdd(array $usersToAdd): void
	{
		if (empty($usersToAdd))
		{
			return;
		}

		foreach ($usersToAdd as $userId)
		{
			$relation = $this->getRelations()->getByUserId($userId, $this->getId());
			if ($relation === null)
			{
				continue;
			}
			if ($relation->getUser()->isBot())
			{
				Im\Bot::onJoinChat('chat'.$this->getId(), [
					'CHAT_TYPE' => $this->getType(),
					'MESSAGE_TYPE' => $this->getType(),
					'BOT_ID' => $userId,
					'USER_ID' => $this->getContext()->getUserId(),
					'CHAT_ID' => $this->getId(),
					'CHAT_AUTHOR_ID' => $this->getAuthorId(),
					'CHAT_ENTITY_TYPE' => $this->getEntityType(),
					'CHAT_ENTITY_ID' => $this->getEntityId(),
					'ACCESS_HISTORY' => (int)$relation->getStartCounter() === 0,
					'SILENT_JOIN' => 'Y', // suppress system message
				]);
			}
		}
	}

	public static function isActive(): bool
	{
		return (new Restriction())->isCopilotActive();
	}

	public static function isAvailable(): bool
	{
		return (new Restriction())->isAvailable();
	}

	public static function checkCopilotAvailability(): Result
	{
		$result = new Result();

		if (!Loader::includeModule('imbot'))
		{
			return $result->addError(new ChatError(ChatError::IMBOT_NOT_INSTALLED));
		}

		if (!self::isAvailable())
		{
			return $result->addError(new Error(CopilotError::AI_NOT_AVAILABLE));
		}

		if (!self::isActive())
		{
			return $result->addError(new Error(CopilotError::AI_NOT_ACTIVE));
		}

		if (!AIHelper::getCopilotBotId())
		{
			return $result->addError(new Error(ChatError::COPILOT_NOT_INSTALLED));
		}

		return $result;
	}

	public static function isHistoryAvailable(): bool
	{
		return (new Restriction())->isHistoryAvailable();
	}

	public function deleteUser(int $userId, DeleteUserConfig $config = new DeleteUserConfig()): Result
	{
		if (CopilotChatBot::getBotId() === $userId && $this->getContext()->getUserId() !== $userId)
		{
			return (new Result())->addError(new ChatError(ChatError::COPILOT_DELETE_ERROR));
		}

		return parent::deleteUser($userId, $config);
	}

	protected function getConfigForCascadeAddUsers(AddUsersConfig $config): AddUsersConfig
	{
		return parent::getConfigForCascadeAddUsers($config)
			->addHiddenUserIds([CopilotChatBot::getBotId()])
		;
	}

	public function toPullFormat(): array
	{
		$pull = parent::toPullFormat();
		$pull['ai_provider'] = EngineManager::getDefaultEngineName();

		return $pull;
	}
}
