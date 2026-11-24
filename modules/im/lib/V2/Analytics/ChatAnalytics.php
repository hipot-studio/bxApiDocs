<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Disk\Analytics\DiskAnalytics;
use Bitrix\Disk\Analytics\Enum\ImSection;
use Bitrix\Disk\File;
use Bitrix\Im\V2\Analytics\Event\ChatEvent;
use Bitrix\Im\V2\Analytics\Event\GuestInviteEvent;
use Bitrix\Im\V2\Analytics\Event\MessengerEvent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CollabChat;
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
	protected const READ_ALL = 'read_all';
	protected const FIRST_REPLY = 'first_reply';
	protected const THREAD_ON = 'thread_on';
	protected const THREAD_OFF = 'thread_off';
	protected const TYPE_THREAD_CREATE_PERMISSION = 'thread_create_permission';
	protected const TYPE_THREAD_COMMENT_PERMISSION = 'thread_comment_permission';
	protected const SECTION_EDITOR = 'editor';
	protected const SECTION_THREAD_LIST = 'thread_list';

	/**
	 * Where "follow" and "unfollow" were pressed, keyed by the source the client names. A surface is
	 * one row here and not a value the client composes, so the same gesture reads the same on every
	 * platform: message_plate is the bell on the plate of a discussion under a post and names no menu
	 * item, while context_menu is the item of the context menu of the post itself.
	 */
	private const FOLLOW_SURFACES = [
		'thread_header' => ['section' => 'thread_header'],
		'thread_context_menu' => ['section' => 'chat_window', 'subSection' => 'thread_context_menu'],
		'context_menu' => [
			'section' => 'chat_window',
			'subSection' => 'context_menu',
			'element' => 'more',
		],
		'message_plate' => ['section' => 'chat_window'],
	];

	/**
	 * The surfaces of the feed: the gesture is made outside the discussion, so p1 names the kind of the
	 * chat the discussion belongs to. From inside the discussion p1 stays with the discussion itself.
	 */
	private const FOLLOW_SURFACES_NAMING_PARENT = ['context_menu', 'message_plate'];

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
	 * be a constant with no analytical value); type=copilot, p5=chatId_*, p2 names the kind of the
	 * initiator, as it does for every chat-level label.
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
		;

		return $event;
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

	/**
	 * Following a discussion and dropping it. The places the gesture is made from are told apart by the
	 * surface slots, because the outcome on the server is one and the same and the question the metric
	 * asks is which entry point is used.
	 *
	 * A gesture made from the feed names the kind of the chat the discussion belongs to, one made from
	 * inside the discussion names the discussion - the two values the ARD allows here. The surface says
	 * which of the two it is, because the surface is the only thing that already knows where the user
	 * stood; no other slot of this event carries the kind of the parent any more.
	 *
	 * The source is optional and a source outside the list is read as none: an installed client that
	 * knows nothing of the parameter keeps sending the event it always sent.
	 */
	public function addFollowComments(bool $flag, ?string $source = null): void
	{
		$this->async(function () use ($flag, $source) {
			$surface = self::FOLLOW_SURFACES[$source] ?? [];

			$event = $this
				->createChatEvent($flag ? self::FOLLOW_COMMENTS : self::UNFOLLOW_COMMENTS)
				?->setSection($surface['section'] ?? null)
				->setSubSection($surface['subSection'] ?? null)
				->setElement($surface['element'] ?? null)
				->setP4(null)
				->setP5(null)
			;

			if ($event === null)
			{
				return;
			}

			if (in_array($source, self::FOLLOW_SURFACES_NAMING_PARENT, true))
			{
				$event->setParentChatP1();
			}

			$event->send();
		});
	}

	/**
	 * Every discussion of a chat marked read at once. The list of threads joins the surfaces the same
	 * gesture is already counted from, so the event is the one they send and the surface is all that
	 * tells them apart: which chat was read is no part of the question and stays out of the label.
	 *
	 * The comments of a channel stay silent, as they do for the two events above: im.v2.Chat.Comment.readAll
	 * is the method the header of the comments of a channel has always called, and counting those reads as
	 * threads would inflate the metric with an old mechanic.
	 */
	public function addReadAllThreads(): void
	{
		$this->async(function () {
			if ($this->chat->getThreadSettingsChat() === null)
			{
				return;
			}

			$this
				->createMessengerEvent(self::READ_ALL)
				?->setSection(self::SECTION_THREAD_LIST)
				->send()
			;
		});
	}

	/**
	 * The moment a discussion becomes one. Its creation is not that moment and sends nothing: a
	 * discussion chat is created by merely opening the thread and, in a channel, by publishing the post
	 * at all, so submit_create_new counted rows no user ever asked for.
	 *
	 * The kind of the chat the discussion belongs to reaches p1, because the kind of the discussion
	 * itself is already in the type and the question the metric asks is where threads are being used.
	 * The greeting message is no reply and never triggers this: the caller counts the replies, and a
	 * message without an author is not one of them.
	 *
	 * A discussion that has been counted stays counted: the number of replies it holds can fall back to
	 * zero when the only reply is deleted completely, and the mark is what keeps the next reply written
	 * from counting the same discussion a second time.
	 */
	public function addFirstReply(): void
	{
		$this->async(function () {
			$event = $this->createChatEvent(self::FIRST_REPLY);
			if ($event === null)
			{
				return;
			}

			$event->setChatType()->setParentChatP1()->send();

			// marked after the send and not before it: the discussion is counted once for as long as it
			// lives, so the mark must stand for an event that really left
			if ($this->chat instanceof Chat\CommentChat)
			{
				$this->chat->markFirstReplyCounted();
			}
		});
	}

	/**
	 * The switch of threads in a chat, in both directions: the share of chats that turn threads off is
	 * the honest signal of rejection and is worth as much as the share that turns them back on.
	 */
	public function addThreadsSwitch(bool $enabled): void
	{
		$this->async(function () use ($enabled) {
			$this
				->createChatEvent($enabled ? self::THREAD_ON : self::THREAD_OFF)
				?->setSection(self::SECTION_EDITOR)
				->setP2(null)
				->setP4(null)
				->send()
			;
		});
	}

	/**
	 * The two rules of threads are a change of the permissions of the chat and are told apart by the
	 * type of that event: who opens a thread and who answers in one are set independently, so one
	 * request can carry both and each is counted on its own.
	 *
	 * The event names no source: the rules of a chat are set in one place, and the rows of the
	 * permissions of every other kind of chat carry none either.
	 */
	public function addEditThreadCreatePermission(string $rights): void
	{
		$this->addEditThreadPermission(self::TYPE_THREAD_CREATE_PERMISSION, $rights);
	}

	public function addEditThreadPostPermission(string $rights): void
	{
		$this->addEditThreadPermission(self::TYPE_THREAD_COMMENT_PERMISSION, $rights);
	}

	private function addEditThreadPermission(string $type, string $rights): void
	{
		if (!$this->isFirstTimeEvent(self::EDIT_PERMISSIONS, $type))
		{
			return;
		}

		$this->async(function () use ($type, $rights) {
			$this
				->createChatEvent(self::EDIT_PERMISSIONS)
				?->setType($type)
				->setP3(self::resolvePermissionValue($rights))
				->send()
			;
		});
	}

	/**
	 * The threshold a rule was set to. An unknown value leaves the slot empty instead of naming a
	 * threshold that was never set.
	 */
	private static function resolvePermissionValue(string $rights): ?string
	{
		return match ($rights)
		{
			Chat::MANAGE_RIGHTS_MEMBER => 'permission_all',
			Chat::MANAGE_RIGHTS_MANAGERS => 'permission_admin',
			Chat::MANAGE_RIGHTS_OWNER => 'permission_owner',
			default => null,
		};
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

	protected function createMessengerEvent(string $eventName): ?MessengerEvent
	{
		if (!$this->isChatTypeAllowed($this->chat, $eventName))
		{
			return null;
		}

		return new MessengerEvent($eventName, $this->chat, $this->getContext()->getUserId());
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

	protected function isFirstTimeEvent(string $eventName, ?string $type = null): bool
	{
		$key = $type === null ? $eventName : "{$eventName}:{$type}";
		$result = self::$oneTimeEvents[$key][$this->chat->getId()] ?? true;
		self::$oneTimeEvents[$key][$this->chat->getId()] = false;

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
				// a discussion is created by opening a thread and, in a channel, by publishing the post at
				// all, so its creation names no intent to start one: first_reply counts that instead
				Chat\CommentChat::class,
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
