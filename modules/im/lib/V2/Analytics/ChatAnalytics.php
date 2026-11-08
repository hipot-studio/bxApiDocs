<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Disk\Analytics\DiskAnalytics;
use Bitrix\Disk\Analytics\Enum\ImSection;
use Bitrix\Disk\File;
use Bitrix\Im\V2\Analytics\Event\ChatEvent;
use Bitrix\Im\V2\Analytics\Event\GuestInviteEvent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Entity\User\UserCollaber;
use Bitrix\Im\V2\Entity\User\UserExternal;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\InviteMethod;
use Bitrix\ImBot\Bot\Support24;
use Bitrix\Main\Loader;

class ChatAnalytics extends AbstractAnalytics
{
	public const SUBMIT_CREATE_NEW = 'submit_create_new';
	public const ACTIVATE_NEW_CHAT = 'activate_new_chat';
	public const ACTIVATE_SECTION_FROM_TEXT_REQUEST = 'from_text_request';
	public const ACTIVATE_SECTION_OTHER = 'other';
	public const SECTION_CHAT_ADD_POPUP = 'chat_add_popup';
	public const ELEMENT_PLUS_BUTTON = 'plus_button';
	protected const ADD_DEPARTMENT = 'add_department';
	protected const ADD_USER = 'add_user';
	protected const DELETE_USER = 'delete_user';
	protected const FOLLOW_COMMENTS = 'follow_comments';
	protected const UNFOLLOW_COMMENTS = 'unfollow_comments';
	protected const EDIT_DESCRIPTION = 'edit_description';
	protected const DELETE_DEPARTMENT = 'delete_department';
	protected const EDIT_PERMISSIONS = 'edit_permissions';
	protected const SET_TYPE = 'set_type';
	protected const AUTODELETE_ON = 'autodelete_on';
	protected const AUTODELETE_OFF = 'autodelete_off';
	protected const JOIN_CHAT = 'join_chat';
	protected const INVITE_GUEST = 'invite_guest';

	protected static array $oneTimeEvents = [];
	protected static array|bool $blockSingleUserEvents = [];

	public function addSubmitCreateNew(): void
	{
		$this->async(function () {
			$this
				->createChatEvent(self::SUBMIT_CREATE_NEW)
				?->setChatType()
				?->send()
			;
		});
	}

	/**
	 * Emits activate_new_chat (tool=im, category=copilot) — the funnel-bottom label fired on the
	 * real draft→active transition of a copilot chat. Paired with submit_create_new (funnel top).
	 *
	 * c_section: from_text_request (user message, incl. suggest click — byte-identical on the
	 * backend) | other (system messages and non-message activations: pin/description/color/title/update).
	 *
	 * The event carries no p1 per the analyst spec (a copilot-only label, so chatType_copilot would
	 * be a constant with no analytical value); type=copilot, p5=chatId_*, p2 is guest-aware (local).
	 */
	public function addActivateNewChat(string $section): void
	{
		$this->async(fn () => $this->buildActivateNewChatEvent($section)?->send());
	}

	protected function buildActivateNewChatEvent(string $section): ?ChatEvent
	{
		$event = $this->createChatEvent(self::ACTIVATE_NEW_CHAT);
		if ($event === null)
		{
			return null;
		}

		$event
			->setChatType()
			->setP1(null)
			->setSection($section)
			->setP2($this->resolveActivateNewChatP2())
		;

		return $event;
	}

	/**
	 * Guest-aware p2 for activate_new_chat ONLY. The shared ChatEvent::setChatP2 is deliberately
	 * left untouched so that p2 of the other chat-level im-labels (add_user/delete_user/
	 * submit_create_new) is not changed. UserGuest extends UserExternal, so it must be matched first.
	 * Resolved from the analytics context (the pinned initiator), not from the chat context.
	 */
	private function resolveActivateNewChatP2(): string
	{
		$user = $this->getContext()->getUser();

		return match (true)
		{
			$user instanceof UserGuest => 'user_guest',
			$user instanceof UserCollaber => 'user_collaber',
			$user instanceof UserExternal => 'user_extranet',
			default => 'user_intranet',
		};
	}

	public function addAddUser(AddUsersConfig $config): void
	{
		if ($this->isSingleUserEventsBlocked())
		{
			return;
		}

		if ($config->skipAnalytics)
		{
			return;
		}

		$this->async(function () {
			$this
				->createChatEvent(self::ADD_USER)
				?->send()
			;
			if ($this->chat instanceof Chat\CopilotChat)
			{
				(new CopilotAnalytics($this->chat))->addAddUser();
			}
		});
	}

	public function addDeleteUser(): void
	{
		if ($this->isSingleUserEventsBlocked())
		{
			return;
		}

		$this->async(function () {
			$this
				->createChatEvent(self::DELETE_USER)
				?->send()
			;
			if ($this->chat instanceof Chat\CopilotChat)
			{
				(new CopilotAnalytics($this->chat))->addDeleteUser();
			}
		});
	}

	public function addFollowComments(bool $flag): void
	{
		$this->async(function () use ($flag) {
			$this
				->createChatEvent($flag ? self::FOLLOW_COMMENTS : self::UNFOLLOW_COMMENTS)
				?->send()
			;
		});
	}

	public function addAutoDeleteOn(int $messagesAutoDeleteDelay): void
	{
		$this->async(function () use ($messagesAutoDeleteDelay) {
			$this
				->createChatEvent(self::AUTODELETE_ON)
				?->setP2(null)
				->setP3('timer_' . $messagesAutoDeleteDelay)
				->setP4(null)
				->setP5(null)
				->send()
			;
		});
	}

	public function addAutoDeleteOff(): void
	{
		$this->async(function () {
			$this
				->createChatEvent(self::AUTODELETE_OFF)
				?->setP2(null)
				->setP3(null)
				->setP4(null)
				->setP5(null)
				->send()
			;
		});
	}

	public function addJoinChatByGuestLink(GuestChatLink $link): void
	{
		$this->async(function () use ($link) {
			$this
				->createChatEvent(self::JOIN_CHAT)
				?->setType($link->getInviteMethod()->value)
				->send()
			;
		});
	}

	public function addInviteGuest(InviteMethod $method): void
	{
		$this->async(function () use ($method) {
			$this
				->createGuestInviteEvent(self::INVITE_GUEST)
				?->setType($method->value)
				->setP4(null)
				->setP5(null)
				->send()
			;
		});
	}

	protected function createGuestInviteEvent(string $eventName): ?GuestInviteEvent
	{
		if (!$this->isChatTypeAllowed($this->chat, $eventName))
		{
			return null;
		}

		return new GuestInviteEvent($eventName, $this->chat, $this->getContext()->getUserId());
	}

	protected function addChatEditEvent(string $eventName, ?string $section = null, ?string $element = null): void
	{
		if ($this->chat instanceof CollabChat)
		{
			return;
		}

		$this->async(function () use ($eventName, $section, $element) {
			$this
				->createChatEvent($eventName)
				?->setSection($section)
				?->setElement($element)
				?->send()
			;
		});
	}

	public function addEditDescription(): void
	{
		$this->async(function () {
			$this
				->createChatEvent(self::EDIT_DESCRIPTION)
				?->send()
			;
		});
	}

	public function addAddDepartment(?string $section = null, ?string $element = null): void
	{
		$this->addChatEditEvent(self::ADD_DEPARTMENT, $section, $element);
	}

	public function addDeleteDepartment(): void
	{
		$this->addChatEditEvent(self::DELETE_DEPARTMENT);
	}

	public function addEditPermissions(): void
	{
		if ($this->isFirstTimeEvent(self::EDIT_PERMISSIONS))
		{
			$this->addChatEditEvent(self::EDIT_PERMISSIONS);
		}
	}

	public function addSetType(): void
	{
		$this->addChatEditEvent(self::SET_TYPE);
	}

	public function addUploadFile(File $file): void
	{
		if (Loader::includeModule('disk'))
		{
			$this->async(fn () => DiskAnalytics::sendUploadFileToImEvent($file, $this->getImSectionForDiskEvent()));
		}
	}

	protected function getImSectionForDiskEvent(): ImSection
	{
		return match ($this->chat->getType())
		{
			Chat::IM_TYPE_CHANNEL, Chat::IM_TYPE_OPEN_CHANNEL => ImSection::Channel,
			Chat::IM_TYPE_OPEN_LINE => ImSection::Openline,
			default => ImSection::Chat
		};
	}

	protected function createChatEvent(
		string $eventName,
	): ?ChatEvent
	{
		if (!$this->isChatTypeAllowed($this->chat, $eventName))
		{
			return null;
		}

		return (new ChatEvent($eventName, $this->chat, $this->getContext()->getUserId()));
	}

	protected function isFirstTimeEvent(string $eventName): bool
	{
		$result = self::$oneTimeEvents[$eventName][$this->chat->getId()] ?? true;
		self::$oneTimeEvents[$eventName][$this->chat->getId()] = false;

		return $result;
	}

	public static function blockSingleUserEvents(?Chat $chat = null): void
	{
		if (self::$blockSingleUserEvents === true)
		{
			return;
		}

		if ($chat === null)
		{
			self::$blockSingleUserEvents = true;

			return;
		}

		self::$blockSingleUserEvents[$chat->getId()] = true;
	}

	public static function unblockSingleUserEventsByChat(Chat $chat): void
	{
		if (is_bool(self::$blockSingleUserEvents))
		{
			return;
		}

		unset(self::$blockSingleUserEvents[$chat->getId()]);
	}

	protected function isSingleUserEventsBlocked(): bool
	{
		if (!is_array(self::$blockSingleUserEvents))
		{
			return true;
		}

		return self::$blockSingleUserEvents[$this->chat->getId()] ?? false;
	}

	protected function isChatTypeAllowed(Chat $chat, ?string $event = null): bool
	{
		// A project chat is an ExternalChat descendant, but its creation is a user action and stays in analytics.
		if ($event === self::SUBMIT_CREATE_NEW && $chat instanceof CollabChat)
		{
			return $this->checkChatEntityTypeAvailability($chat, $event);
		}

		return parent::isChatTypeAllowed($chat, $event);
	}

	protected function getUnavailableChatTypesForEvent(?string $event = null): array
	{
		$unavailableTypes = match ($event)
		{
			self::SUBMIT_CREATE_NEW => [
				Chat\PrivateChat::class,
				Chat\ExternalChat::class,
				// created lazily on the first notification, not by a user action
				Chat\NotifyChat::class,
			],
			default => [],
		};

		return array_merge(parent::getUnavailableChatTypesForEvent($event), $unavailableTypes);
	}

	protected function getUnavailableChatEntityTypesForEvent(?string $event = null): array
	{
		$unavailableEntityTypes = match ($event)
		{
			self::SUBMIT_CREATE_NEW => ['SUPPORT24_QUESTION'] /** @see Support24::CHAT_ENTITY_TYPE */,
			default => [],
		};

		return array_merge(parent::getUnavailableChatEntityTypesForEvent($event), $unavailableEntityTypes);
	}
}
