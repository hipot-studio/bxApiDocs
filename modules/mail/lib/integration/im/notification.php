<?php

namespace Bitrix\Mail\Integration\Im;

use Bitrix\Mail\Helper\AnalyticsHelper;
use Bitrix\Mail\Internal\Async\Message\MailboxAccessNotificationMessage;
use Bitrix\Mail\Internal\Async\Message\OrphanedMailboxAutoDisconnectNotificationMessage;
use Bitrix\Mail\Internal\Async\Message\ScheduledMessageFailedMessage;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Mail\Service\Message\SendFailure;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageErrorText;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Web\Uri;
use \Bitrix\Mail\Internals\MailboxAccessTable;
use \Bitrix\Mail\MailboxTable;

Loc::loadMessages(__FILE__);

class Notification
{
	public const notifierSchemeTypeMail = 'new_message_v2';
	public const notifierSchemeTypeMailTariffRestrictions = 'tariff_restrictions';
	public const notifyPushTagMailMessage = 'MAIL|MESSAGE|%u';
	public const notifyPushTagMailList = 'MAIL|LIST|%u';

	/**
	 * CPushManager::SendAgent() takes deferred push rows older than 15 seconds and is scheduled
	 * every 30 seconds, so 15 minutes is a wide margin for delayed agents and sparse cron.
	 */
	public const deferredPushCancelWindowSeconds = 900;

	/**
	 * The reasons a mailbox of a Mailhost operation is left unserviced that the summary names, in the order its
	 * tail counts them.
	 */
	public const MAILHOST_UNSERVICED_REASONS = [
		'transfer_failed',
		'skipped_by_the_operator',
		'credentials_missing',
		'handover_not_ready',
	];

	/**
	 * The codes of a refusal of a transfer to the mail of Bitrix24 the summary of refusals has phrases for, and the
	 * codes of a hint after them, in the order its tail counts them.
	 */
	public const TRANSFER_REJECTION_CODES = [
		'domain_not_owned',
		'domain_connected_elsewhere',
		'domain_public_mail_provider',
		'domain_platform_name',
		'source_host_not_public',
		'mailbox_limit_reached',
		'state_conflict',
		'owner_locked',
		'owner_mismatch',
	];

	/**
	 * The codes of the summary that are no refusal: the mailbox works, yet its owner differs from the one bound to it
	 * in the mail of Bitrix24. They go under a heading of their own after the refusals and push only with no refusal.
	 */
	public const TRANSFER_REJECTION_HINT_CODES = ['owner_mismatch'];

	public const TRANSFER_REJECTION_DOMAIN_CODES = [
		'domain_not_owned',
		'domain_connected_elsewhere',
		'domain_public_mail_provider',
		'domain_platform_name',
	];

	public const TRANSFER_REJECTIONS_REWRITE_EVENT = 'transfer_rejections_rewrite';

	/** The kinds of a line of the summary of refusals: a domain of no mailbox, mailboxes of a domain, a mailbox. */
	public const TRANSFER_REJECTION_KINDS = ['domain', 'group', 'address'];

	/** One tag for every summary of refusals: by it im replaces the summary a recipient holds and takes it off. */
	public const TRANSFER_REJECTIONS_TAG = 'MAIL|TRANSFER_REJECTIONS';
	public const COMPOSITION_STALL_TAG = 'MAIL|MAILHOST_COMPOSITION_STALLED';

	private const TRANSFER_REJECTIONS_PHRASE = 'MAIL_NOTIFY_TRANSFER_REJECTIONS_';

	/** The parts of the summary, by the prefix of the phrases of their heading and push, in the order they are told. */
	private const TRANSFER_REJECTIONS_REFUSALS = '';
	private const TRANSFER_REJECTIONS_HINTS = 'HINTS_';
	private const TRANSFER_REJECTIONS_PARTS = [self::TRANSFER_REJECTIONS_REFUSALS, self::TRANSFER_REJECTIONS_HINTS];

	//region General methods

	public static function getSchema()
	{
		Main\Loader::includeModule('im');

		return [
			'mail' => [
				'PRODUCT_SOURCE' => [
					'ID' => 'mail',
					'NAME' => Loc::getMessage('MAIL_NOTIFY_PRODUCT_SOURCE_NAME'),
					'DESCRIPTION' => Loc::getMessage('MAIL_NOTIFY_PRODUCT_SOURCE_DESCRIPTION'),
					'ICON' => ['TYPE' => 'named', 'VALUE' => 'mail'],
					'SORT' => 800,
				],
				'NOTIFY' => [
					self::notifierSchemeTypeMail => [
						'NAME' => Loc::getMessage('MAIL_NOTIFY_NEW_MESSAGE'),
						'SITE' => 'Y',
						'SYSTEM' => 'Y',
						'MAIL' => 'N',
						'PUSH' => 'Y',
						'DISABLED' => [
							IM_NOTIFY_FEATURE_MAIL,
						],
					],
					'imposed_tariff_restrictions_on_the_mailbox' => [
						'NAME' => Loc::getMessage('MAIL_NOTIFY_IMPOSE_TARIFF_RESTRICTIONS_ON_THE_MAILBOX'),
						'SITE' => 'Y',
						'SYSTEM' => 'Y',
						'MAIL' => 'Y',
						'PUSH' => 'Y',
						'DISABLED' => [
							IM_NOTIFY_FEATURE_PUSH,
							IM_NOTIFY_FEATURE_MAIL,
							IM_NOTIFY_FEATURE_SITE,
						],
					],
				],
			],
		];
	}

	private static function getMailboxUrl(int $mailboxId, bool $absoluteUrl = false): string
	{
		$url = htmlspecialcharsbx(sprintf("/mail/list/%u", $mailboxId));

		if ($absoluteUrl)
		{
			$uri = new Uri($url);

			return $uri->toAbsolute()->getLocator();
		}

		$url = AnalyticsHelper::addSourceAnalyticsToMessage($url, AnalyticsHelper::ENTITY_TYPE_NOTIFICATION);

		return $url;
	}

	/** Screen of one scheduled letter, the only place its reason and the way to change it are shown. */
	private static function getScheduledMessageUrl(int $mailboxId, int $scheduledMessageId): string
	{
		return htmlspecialcharsbx(sprintf('/mail/scheduled/%u/%u', $mailboxId, $scheduledMessageId));
	}

	private static function getMailboxEditUrl(int $mailboxId, bool $absoluteUrl = false): string
	{
		$url = htmlspecialcharsbx(sprintf("/mail/config/edit?id=%u", $mailboxId));

		if ($absoluteUrl)
		{
			$uri = new Uri($url);

			return $uri->toAbsolute()->getLocator();
		}

		return $url;
	}

	private static function getUserGenderById(int $userId): string
	{
		static $cache = [];

		if ($userId <= 0)
		{
			return 'M';
		}

		if (array_key_exists($userId, $cache))
		{
			return $cache[$userId];
		}

		try
		{
			$row = \Bitrix\Main\UserTable::getList([
				'select' => ['PERSONAL_GENDER'],
				'filter' => ['=ID' => $userId],
				'limit'  => 1,
			])->fetch();
		}
		catch (\Throwable $e)
		{
			$cache[$userId] = null;

			return 'M';
		}

		$gender = $row['PERSONAL_GENDER'] ?? null;
		$cache[$userId] = $gender === 'F' ? 'F' : 'M';

		return $cache[$userId];
	}

	public static function getPhraseKeyWithGenderSuffix(string $phraseKey, int $userId): string
	{
		if ($phraseKey === '' || $userId <= 0)
		{
			return $phraseKey;
		}

		$gender = self::getUserGenderById($userId);

		return $gender === 'F' ? $phraseKey . '_F' : $phraseKey . '_M';
	}

	//endregion

	private static function getNotifyMessageForNewMessageSetInMail($mailboxId, $messageCount, $absoluteUrl = false): \Closure
	{
		$url = self::getMailboxUrl($mailboxId, $absoluteUrl);

		return fn (?string $languageId = null) => Loc::getMessage(
			'MAIL_NOTIFY_NEW_MESSAGE_MULTI_1',
			[
				'#COUNT#' => $messageCount,
				'#VIEW_URL#' => $url,
			],
			$languageId,
		);
	}

	private static function getPushMessageForNewMessage($message, $messageCount): \Closure
	{
		if (empty($message))
		{
			return static fn (?string $languageId = null) => Loc::getMessage(
				'MAIL_PUSH_NOTIFY_NEW_MESSAGE_MULTI',
				['#COUNT#' => $messageCount],
				$languageId,
			);
		}

		if ($message['SUBJECT'])
		{
			return static fn (?string $languageId = null) => Loc::getMessage(
				'MAIL_PUSH_NOTIFY_NEW_SINGLE_MESSAGE_IN_MAIL_CLIENT',
				['#SUBJECT#' => $message['SUBJECT']],
				$languageId,
			);
		}

		return static fn (?string $languageId = null) => Loc::getMessage(
			'MAIL_PUSH_NOTIFY_NEW_SINGLE_MESSAGE_IN_MAIL_CLIENT_EMPTY_SUBJECT',
			$languageId,
		);
	}

	private static function getPushTagForNewMessage($message, $mailboxId): string
	{
		if (empty($message))
		{
			return sprintf(self::notifyPushTagMailList, $mailboxId);
		}

		return self::getPushTagForMessageId((int)$message['ID']);
	}

	private static function getPushTagForMessageId(int $messageId): string
	{
		return sprintf(self::notifyPushTagMailMessage, $messageId);
	}

	/**
	 * Notification and its cancellation must produce the same string, or the push is not found.
	 */
	private static function getNotifySubTag(string $notifyTag, int $userId): string
	{
		return $notifyTag . '|' . $userId;
	}

	public static function cancelDeferredPushForReadMessages(int $mailboxId, array $messageIds, int $userId): void
	{
		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		foreach ($messageIds as $messageId)
		{
			\CIMNotify::DeleteBySubTag(
				self::getNotifySubTag(self::getPushTagForMessageId((int)$messageId), $userId),
			);
		}

		$aggregateTag = self::getPushTagForNewMessage([], $mailboxId);
		\CIMNotify::DeleteBySubTag(self::getNotifySubTag($aggregateTag, $userId));
	}

	private static function getNotifyMessageForNewMessageInMail($message, $absoluteUrl = false): \Closure
	{
		$url = htmlspecialcharsbx($message['__href']);

		if ($absoluteUrl)
		{
			$uri = new Uri($url);
			$url = $uri->toAbsolute()->getLocator();
		}

		$url = AnalyticsHelper::addSourceAnalyticsToMessage($url, AnalyticsHelper::ENTITY_TYPE_NOTIFICATION);

		if ($message['SUBJECT'])
		{
			return fn (?string $languageId = null) => Loc::getMessage(
				'MAIL_NOTIFY_NEW_SINGLE_MESSAGE_IN_MAIL_CLIENT_1',
				[
					'#SUBJECT#' => $message['SUBJECT'],
					'#VIEW_URL#' => $url,
				],
				$languageId,
			);
		}

		return fn (?string $languageId = null) => Loc::getMessage(
			'MAIL_NOTIFY_NEW_SINGLE_MESSAGE_IN_MAIL_CLIENT_EMPTY_SUBJECT',
			[
				'#VIEW_URL#' => $url,
			],
			$languageId,
		);
	}

	private static function getNotifyMessageForTariffRestrictionsMailbox($mailboxId, $email, $forEmailNotification = false): \Closure
	{
		$url = self::getMailboxUrl($mailboxId, $forEmailNotification);

		if ($forEmailNotification)
		{
			$emailWithHref = "<a target=\"_blank\" href=\"$url\">$email</a>";

			return fn (?string $languageId = null) => Loc::getMessage(
				'MAIL_NOTIFY_FULL_MAILBOX_TARIFF_RESTRICTIONS_HAVE_BEEN_IMPOSED',
				[
					'#EMAIL#' => $emailWithHref,
				],
				$languageId,
			);
		}

		return fn (?string $languageId = null) => Loc::getMessage(
			'MAIL_NOTIFY_MAILBOX_TARIFF_RESTRICTIONS_HAVE_BEEN_IMPOSED',
			[
				'#EMAIL#' => $email,
				'#VIEW_URL#' => $url,
			],
			$languageId,
		);
	}

	private static function notifyForNewMessagesInMail($userId, $fields): void
	{
		$message = $fields['message'];

		$notifyTitleCallback = fn (?string $languageId = null) => Loc::getMessage(
			'MAIL_NOTIFY_NEW_MESSAGE_TITLE',
			language: $languageId,
		);

		$notifyTag = self::getPushTagForNewMessage($message, $fields['mailboxId']);

		\CIMNotify::add([
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_EVENT' => self::notifierSchemeTypeMail,
			// NOTIFY_TAG is deliberately absent: it switches im to the deduplicating path, which would
			// replace the previous notification and scan other recipients of a shared mailbox.
			// Cancellation needs only the sub-tag - both the notification and the push queue are found by it.
			'NOTIFY_SUB_TAG' => self::getNotifySubTag($notifyTag, $userId),
			'NOTIFY_TITLE' => $notifyTitleCallback,
			'PUSH_PARAMS' => [
				'ACTION' => 'mail',
				'TAG' => $notifyTag,
				'ADVANCED_PARAMS' => [
					'id' => 'im_notify',
					'group' => 'im_notify',
					'senderName' => Loc::getMessage('MAIL_NOTIFY_NEW_MESSAGE_TITLE'),
				],
				['TAG' => 'IM_NOTIFY'],
			],
			'PUSH_MESSAGE' => self::getPushMessageForNewMessage($message, $fields['count']),
			'NOTIFY_MESSAGE_OUT' => empty($message)
				? self::getNotifyMessageForNewMessageSetInMail($fields['mailboxId'], $fields['count'], true)
				: self::getNotifyMessageForNewMessageInMail($message, true),
			'NOTIFY_MESSAGE' => empty($message)
				? self::getNotifyMessageForNewMessageSetInMail($fields['mailboxId'], $fields['count'])
				: self::getNotifyMessageForNewMessageInMail($message),
			'TO_USER_ID' => $userId,
		]);
	}

	private static function notifyForTariffRestrictions($mailboxId): void
	{
		$mailbox = MailboxTable::getList([
			'select' => [
				'USER_ID',
				'EMAIL',
			],
			'filter' => [
				'=ID' => $mailboxId,
			],
			'limit' => 1,
		])->fetch();

		$notifyTitleCallback = fn (?string $languageId = null) => Loc::getMessage(
			'MAIL_NOTIFY_NEW_MESSAGE_TITLE',
			language: $languageId,
		);

		if (isset($mailbox['USER_ID']) && isset($mailbox['EMAIL']))
		{
			\CIMNotify::add([
				'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
				'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => 'mail',
				'NOTIFY_EVENT' => self::notifierSchemeTypeMailTariffRestrictions,
				'NOTIFY_TITLE' => $notifyTitleCallback,
				'NOTIFY_MESSAGE_OUT' => self::getNotifyMessageForTariffRestrictionsMailbox($mailboxId, $mailbox['EMAIL'], true),
				'NOTIFY_MESSAGE' => self::getNotifyMessageForTariffRestrictionsMailbox($mailboxId, $mailbox['EMAIL']),
				'TO_USER_ID' => $mailbox['USER_ID'],
			]);
		}
	}

	public static function add($userId, $type, $fields, $mailboxId = null)
	{
		if (Main\Loader::includeModule('im'))
		{
			if ($type == self::notifierSchemeTypeMail)
			{
				$mailboxId = $fields['mailboxId'];

				$userIds = [];

				$mailboxOwnerId = (int)$fields['mailboxOwnerId'] ?? 0;

				if ($mailboxOwnerId)
				{
					$userIds = MailboxAccessTable::getUserIdsWithAccessToTheMailbox($mailboxId);
				}
				else
				{
					$userIds[] = $userId;
				}

				foreach ($userIds as $id)
				{
					self::notifyForNewMessagesInMail($id, $fields);
				}
			}
			elseif ($type == 'imposed_tariff_restrictions_on_the_mailbox')
			{
				self::notifyForTariffRestrictions($mailboxId);
			}
		}
	}

	public static function sendAddMailboxNotification(int $mailboxId, string $email, int $toUserId, int $fromUserId): void
	{
		$url = self::getMailboxUrl($mailboxId);
		$subjectCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_MASS_CONNECTING_MAILBOX_NOTIFICATION_SUBJECT',
			$fromUserId,
		);

		$notifySubjectCallback = fn (?string $languageId = null) => Loc::getMessage(
			$subjectCode,
			[
				'#LINK#' => $url,
				'#EMAIL#' => $email,
			],
			language: $languageId,
		);

		\CIMNotify::add([
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_MESSAGE_OUT' => self::getNotifyMessageForAddMailbox($mailboxId, $email, $fromUserId, forEmailNotification: true),
			'NOTIFY_MESSAGE' => self::getNotifyMessageForAddMailbox($mailboxId, $email, $fromUserId),
			'TO_USER_ID' => $toUserId,
			'FROM_USER_ID' => $fromUserId,
			"PARAMS" => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $notifySubjectCallback,
				],
			],
		]);
	}

	public static function getNotifyMessageForAddMailbox(int $mailboxId, string $email, int $userId, bool $forEmailNotification = false): \Closure
	{
		$url = self::getMailboxUrl($mailboxId, $forEmailNotification);
		$code = self::getPhraseKeyWithGenderSuffix(
			'MAIL_MASS_CONNECTING_MAILBOX_NOTIFICATION_NOTIFY_MESSAGE',
			$userId,
		);

		if ($forEmailNotification)
		{
			$email = htmlspecialcharsbx($email);
			$email = "<a target=\"_blank\" href=\"$url\">$email</a>";
		}

		return fn (?string $languageId = null) => Loc::getMessage(
			$code,
			[
				'#EMAIL#' => $email,
			],
			$languageId,
		);
	}

	public static function sendEditMailboxNotifications(array $mailbox, int $originalOwnerId, int $finalOwnerId): void
	{
		global $USER;
		$editorUserId = (int)$USER->getId();

		$hasOwnerChanged = $finalOwnerId !== $originalOwnerId;

		$mailboxId = (int)$mailbox['ID'];
		$mailboxUrl = self::getMailboxUrl($mailboxId);
		$mailboxWithBBCode = "[url={$mailboxUrl}] " . htmlspecialcharsbx($mailbox['EMAIL']) . " [/url]";

		if ($hasOwnerChanged && $editorUserId !== $finalOwnerId)
		{
			self::notifyFinalOwnerAboutOwnershipChange($finalOwnerId, $editorUserId, $mailboxWithBBCode);
		}
		elseif ($editorUserId !== $finalOwnerId)
		{
			self::notifyFinalOwnerAboutSettingsChange($finalOwnerId, $editorUserId, $mailboxWithBBCode);
		}
	}

	public static function notifyFinalOwnerAboutOwnershipChange(
		int $finalOwnerId,
		int $editorUserId,
		string $mailboxWithBBCode,
	): void
	{
		$replacements = ['#EMAIL#' => $mailboxWithBBCode];

		$notifyMessageCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_TO_NOTIFY_MESSAGE',
			$editorUserId,
		);

		$notifyMessage = self::getNotificationMessageCallback(
			$notifyMessageCode,
			$replacements,
		);

		$subjectCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_TO_NOTIFY_MESSAGE_SUBJECT',
			$editorUserId,
		);

		$subject = self::getNotificationMessageCallback(
			$subjectCode,
			$replacements,
		);

		\CIMNotify::Add([
			"TO_USER_ID" => $finalOwnerId,
			"NOTIFY_TYPE" => IM_NOTIFY_FROM,
			"FROM_USER_ID" => $editorUserId,
			"NOTIFY_MODULE" => 'mail',
			"NOTIFY_MESSAGE" => $notifyMessage,
			"PARAMS" => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
				],
			],
		]);
	}

	public static function notifyFinalOwnerAboutSettingsChange(
		int $finalOwnerId,
		int $editorUserId,
		string $mailboxWithBBCode,
	): void
	{
		$replacements = ['#EMAIL#' => $mailboxWithBBCode];

		$notifyMessageCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_CLIENT_CONFIG_HAS_CHANGED_NOTIFY_MESSAGE',
			$editorUserId,
		);

		$notifyMessage = self::getNotificationMessageCallback(
			$notifyMessageCode,
			$replacements,
		);

		$subjectCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_CLIENT_CONFIG_HAS_CHANGED_NOTIFY_MESSAGE_PARAMS',
			$editorUserId,
		);

		$subject = self::getNotificationMessageCallback(
			$subjectCode,
			$replacements,
		);

		\CIMNotify::Add([
			"TO_USER_ID" => $finalOwnerId,
			"NOTIFY_TYPE" => IM_NOTIFY_FROM,
			"FROM_USER_ID" => $editorUserId,
			"NOTIFY_MODULE" => 'mail',
			"NOTIFY_MESSAGE" => $notifyMessage,
			"PARAMS" => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
				],
			],
		]);
	}

	public static function sendPasswordlessRequestNotification(
		int $userId,
		int $adminId,
		int $mailboxId,
	): void
	{
		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		$subjectCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_PASSWORDLESS_REQUEST_NOTIFICATION_SUBJECT',
			$adminId,
		);

		$subjectCallback = self::getNotificationMessageCallback(
			$subjectCode,
		);

		$notifyMessageCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_PASSWORDLESS_REQUEST_NOTIFICATION_MESSAGE',
			$adminId,
		);

		$notifyMessageCallback = self::getNotificationMessageCallback(
			$notifyMessageCode,
		);

		$componentParams = [
			'SUBJECT' => $subjectCallback,
			'LINKS' => [
				[
					'title' => Loc::getMessage('MAIL_PASSWORDLESS_REQUEST_NOTIFICATION_LINK'),
					'href' => '/mail/',
					'accent' => true,
				],
			],
		];

		\CIMNotify::Add([
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|PASSWORDLESS_REQUEST|' . $mailboxId,
			'NOTIFY_MESSAGE' => $notifyMessageCallback,
			'TO_USER_ID' => $userId,
			'FROM_USER_ID' => $adminId,
			'PARAMS' => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => $componentParams,
			],
		]);
	}

	public static function deletePasswordlessRequestNotification(int $mailboxId): void
	{
		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		\CIMNotify::DeleteByTag('MAIL|PASSWORDLESS_REQUEST|' . $mailboxId);
	}

	public static function getNotificationMessageCallback(string $messageCode, array $replacements = []): callable
	{
		return fn (?string $languageId = null) => Loc::getMessage(
			$messageCode,
			$replacements,
			$languageId,
		);
	}

	public static function dispatchAccessChangedNotifications(
		int $mailboxId,
		string $mailboxEmail,
		array $previousAccessCodes,
		array $currentAccessCodes,
		int $editorUserId,
		int $mailboxOwnerId = 0,
	): void
	{
		$message = new MailboxAccessNotificationMessage(
			mailboxId: $mailboxId,
			mailboxEmail: $mailboxEmail,
			previousAccessCodes: $previousAccessCodes,
			currentAccessCodes: $currentAccessCodes,
			editorUserId: $editorUserId,
			mailboxOwnerId: $mailboxOwnerId,
		);

		$message->send('mail_access_notification');
	}

	public static function notifyUserAboutAccessGranted(
		int $toUserId,
		int $fromUserId,
		int $mailboxId,
		string $mailboxEmail,
	): void
	{
		$url = self::getMailboxUrl($mailboxId);
		$safeEmail = htmlspecialcharsbx($mailboxEmail);
		$emailWithLink = "[url={$url}]{$safeEmail}[/url]";

		$notifyMessageCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_NOTIFY_ACCESS_GRANTED_MESSAGE',
			$fromUserId,
		);

		$notifyMessage = self::getNotificationMessageCallback(
			$notifyMessageCode,
			[
				'#EMAIL#' => $safeEmail,
				'#VIEW_URL#' => $url,
			],
		);

		$subjectCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_NOTIFY_ACCESS_GRANTED_SUBJECT',
			$fromUserId,
		);

		$subject = self::getNotificationMessageCallback(
			$subjectCode,
			['#EMAIL#' => $emailWithLink],
		);

		\CIMNotify::Add([
			'TO_USER_ID' => $toUserId,
			'FROM_USER_ID' => $fromUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|ACCESS_GRANTED|' . $mailboxId,
			'NOTIFY_MESSAGE' => $notifyMessage,
			'PARAMS' => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
				],
			],
		]);
	}

	public static function notifyUserAboutAccessRevoked(
		int $toUserId,
		int $fromUserId,
		int $mailboxId,
		string $mailboxEmail,
	): void
	{
		$safeEmail = htmlspecialcharsbx($mailboxEmail);

		$notifyMessageCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_NOTIFY_ACCESS_REVOKED_MESSAGE',
			$fromUserId,
		);

		$notifyMessage = self::getNotificationMessageCallback(
			$notifyMessageCode,
			['#EMAIL#' => $safeEmail],
		);

		$subjectCode = self::getPhraseKeyWithGenderSuffix(
			'MAIL_NOTIFY_ACCESS_REVOKED_SUBJECT',
			$fromUserId,
		);

		$subject = self::getNotificationMessageCallback(
			$subjectCode,
			['#EMAIL#' => $safeEmail],
		);

		\CIMNotify::Add([
			'TO_USER_ID' => $toUserId,
			'FROM_USER_ID' => $fromUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|ACCESS_REVOKED|' . $mailboxId,
			'NOTIFY_MESSAGE' => $notifyMessage,
			'PARAMS' => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
				],
			],
		]);
	}

	public static function notifyUserAboutMailboxMigration(
		int $toUserId,
		int $mailboxId,
		string $mailboxEmail,
		string $status,
	): void
	{
		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		$tag = 'MAIL|MIGRATION|' . $mailboxId . '|' . $status;

		$phrase = match ($status)
		{
			'done', 'cancelled' => 'MAIL_NOTIFY_MIGRATION_DONE',
			'running', 'waiting', 'blocked', 'switching' => 'MAIL_NOTIFY_MIGRATION_STARTED',
			default => null,
		};
		if ($phrase === null)
		{
			return;
		}

		$safeEmail = htmlspecialcharsbx($mailboxEmail);
		$message = static fn (?string $languageId = null) => Loc::getMessage(
			$phrase,
			['#EMAIL#' => $safeEmail],
			$languageId,
		) ?: Loc::getMessage('MAIL_NOTIFY_NEW_MESSAGE_TITLE', [], $languageId);

		\CIMNotify::Add([
			'TO_USER_ID' => $toUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => $tag,
			'NOTIFY_MESSAGE' => $message,
			'PARAMS' => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => ['SUBJECT' => $message],
			],
		]);
	}

	public static function notifyAdminAboutDeletedMailhostMailbox(
		int $adminUserId,
		int $mailboxId,
		string $mailboxEmail,
	): bool
	{
		if (!Main\Loader::includeModule('im'))
		{
			return false;
		}

		$message = self::getNotificationMessageCallback(
			'MAIL_NOTIFY_MAILHOST_MAILBOX_DELETED',
			['#EMAIL#' => htmlspecialcharsbx($mailboxEmail)],
		);
		return (int)\CIMNotify::Add([
			'TO_USER_ID' => $adminUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|MAILHOST_DELETED|' . $mailboxId,
			'NOTIFY_MESSAGE' => $message,
		]) > 0;
	}

	/**
	 * The summary of the mailboxes a Mailhost operation left unserviced: the named ones under the phrases of their
	 * reasons, the rest counted by reason, and the way to support last. The tag is one per operation, so a later
	 * summary replaces the earlier one.
	 *
	 * @param list<array{email: string, reason: string}> $named
	 * @param array<string, int> $remainder the mailboxes left unnamed, by the word of their reason
	 */
	public static function notifyAdminAboutUnservicedMailhostMailboxes(
		int $adminUserId,
		string $operationId,
		array $named,
		array $remainder,
	): bool
	{
		if (!Main\Loader::includeModule('im'))
		{
			return false;
		}

		$items = [];
		foreach ($named as $item)
		{
			$items[] = [
				'email' => self::escapeMailhostAddress((string)$item['email']),
				'reason' => (string)$item['reason'],
			];
		}
		$rest = [];
		foreach (self::MAILHOST_UNSERVICED_REASONS as $reason)
		{
			$rest[$reason] = max(0, (int)($remainder[$reason] ?? 0));
		}
		$count = count($items) + array_sum($rest);

		return (int)\CIMNotify::Add([
			'TO_USER_ID' => $adminUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|MAILHOST_UNSERVICED|' . $operationId,
			'NOTIFY_MESSAGE' => static fn (?string $languageId = null): string => self::getUnservicedMailhostMessage(
				$items,
				$rest,
				$languageId,
			),
			'PUSH_MESSAGE' => static fn (?string $languageId = null): string => (string)Loc::getMessagePlural(
				'MAIL_NOTIFY_MAILHOST_UNSERVICED_RESULT_PUSH',
				$count,
				['#COUNT#' => (string)$count],
				$languageId,
			),
		]) > 0;
	}

	/**
	 * Escaped once. A zero-width space follows every bracket: an entity of a bracket comes back as the bracket by
	 * the time the client of im reads the text, and a bracket next to a word opens a BB code or a Markdown link there.
	 */
	private static function escapeMailhostAddress(string $email): string
	{
		return strtr(htmlspecialcharsbx($email), ['[' => "[\u{200B}", ']' => "]\u{200B}"]);
	}

	/**
	 * @param list<array{email: string, reason: string}> $items the addresses escaped already
	 * @param array<string, int> $rest
	 */
	private static function getUnservicedMailhostMessage(array $items, array $rest, ?string $languageId): string
	{
		$lines = [(string)Loc::getMessage('MAIL_NOTIFY_MAILHOST_UNSERVICED_RESULT_HEADING', null, $languageId)];
		foreach ($items as $item)
		{
			$lines[] = (string)Loc::getMessage(
				'MAIL_NOTIFY_MAILHOST_UNSERVICED_ITEM_' . strtoupper($item['reason']),
				['#EMAIL#' => $item['email']],
				$languageId,
			);
		}
		foreach ($rest as $reason => $count)
		{
			if ($count > 0)
			{
				$lines[] = (string)Loc::getMessagePlural(
					'MAIL_NOTIFY_MAILHOST_UNSERVICED_MORE_' . strtoupper($reason),
					$count,
					['#COUNT#' => (string)$count],
					$languageId,
				);
			}
		}
		$lines[] = (string)Loc::getMessage('MAIL_NOTIFY_MAILHOST_UNSERVICED_SUPPORT', null, $languageId);

		return implode("\n", $lines);
	}

	/**
	 * The summary of the refusals of a transfer to the mail of Bitrix24: the named lines under the phrases of their
	 * kinds and codes, the rest counted by code. Every summary is under one tag.
	 *
	 * The refusals go under their heading, the hints under theirs after them; a part with nothing is left out. The push
	 * of a new summary counts the refusals, or the hints of a summary of hints alone; a rewrite pushes one phrase of
	 * its own with no count and goes without mail.
	 *
	 * @param list<array{kind: string, name: string, code: string, count: int}> $lines the count is the one of the
	 * mailboxes of a group, 1 for a line of another kind
	 * @param array<string, int> $remainder the pairs left unnamed, by the code of their refusal
	 */
	public static function notifyAdminAboutTransferRejections(
		int $adminUserId,
		array $lines,
		array $remainder,
		bool $rewrite = false,
	): bool
	{
		if (!Main\Loader::includeModule('im'))
		{
			return false;
		}

		// The refusals and the hints by the prefix of the phrases of their heading and push, in the order told.
		$parts = [];
		foreach (self::TRANSFER_REJECTIONS_PARTS as $part)
		{
			$parts[$part] = ['items' => [], 'rest' => [], 'count' => 0];
		}
		foreach ($lines as $line)
		{
			$kind = (string)$line['kind'];
			$code = (string)$line['code'];
			$count = $kind === 'group' ? max(0, (int)$line['count']) : 1;
			$part = self::getTransferRejectionsPart($code);
			$parts[$part]['items'][] = [
				'kind' => $kind,
				'name' => self::escapeMailhostAddress((string)$line['name']),
				'code' => $code,
				'count' => $count,
			];
			$parts[$part]['count'] += $count;
		}
		foreach (self::TRANSFER_REJECTION_CODES as $code)
		{
			$count = max(0, (int)($remainder[$code] ?? 0));
			$part = self::getTransferRejectionsPart($code);
			$parts[$part]['rest'][$code] = $count;
			$parts[$part]['count'] += $count;
		}

		$fields = [
			'TO_USER_ID' => $adminUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => self::TRANSFER_REJECTIONS_TAG,
			'NOTIFY_MESSAGE' => static fn (?string $languageId = null): string => self::getTransferRejectionsMessage(
				$parts,
				$languageId,
			),
		];
		if ($rewrite)
		{
			$fields['NOTIFY_EVENT'] = self::TRANSFER_REJECTIONS_REWRITE_EVENT;
			$fields['NOTIFY_MESSAGE_OUT'] = IM_MAIL_SKIP;
			$fields['PUSH_MESSAGE'] = static fn (?string $languageId = null): string =>
				(string)Loc::getMessage('MAIL_NOTIFY_TRANSFER_REJECTIONS_REWRITE_PUSH', null, $languageId);
		}
		else
		{
			$refusals = $parts[self::TRANSFER_REJECTIONS_REFUSALS]['count'];
			$part = $refusals === 0 && $parts[self::TRANSFER_REJECTIONS_HINTS]['count'] > 0
				? self::TRANSFER_REJECTIONS_HINTS
				: self::TRANSFER_REJECTIONS_REFUSALS
			;
			$count = $parts[$part]['count'];
			$fields['PUSH_MESSAGE'] = static function (?string $languageId = null) use ($part, $count): string {
				$culture = Main\Context::getCurrent()->getCulture();

				return (string)Loc::getMessagePlural(
					self::TRANSFER_REJECTIONS_PHRASE . $part . 'PUSH',
					$count,
					[
						'#COUNT#' => number_format(
							$count,
							0,
							$culture->getNumberDecimalSeparator(),
							$culture->getNumberThousandsSeparator(),
						),
					],
					$languageId,
				);
			};
		}

		return (int)\CIMNotify::Add($fields) > 0;
	}

	public static function notifyAdminAboutStalledCompositionRead(int $adminUserId, string $reason): bool
	{
		$phrase = match ($reason)
		{
			'access_refused' => 'MAIL_NOTIFY_COMPOSITION_STALL_ACCESS_REFUSED',
			'operation_disabled' => 'MAIL_NOTIFY_COMPOSITION_STALL_OPERATION_DISABLED',
			default => null,
		};
		if ($phrase === null || !Main\Loader::includeModule('im'))
		{
			return false;
		}

		return (int)\CIMNotify::Add([
			'TO_USER_ID' => $adminUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => self::COMPOSITION_STALL_TAG,
			'NOTIFY_MESSAGE' => static fn (?string $languageId = null): string =>
				(string)Loc::getMessage($phrase, null, $languageId),
			'PUSH_MESSAGE' => static fn (?string $languageId = null): string =>
				(string)Loc::getMessage('MAIL_NOTIFY_COMPOSITION_STALL_PUSH', null, $languageId),
		]) > 0;
	}

	public static function withdrawStalledCompositionReadNotice(): bool
	{
		if (!Main\Loader::includeModule('im'))
		{
			return false;
		}

		return (bool)\CIMNotify::DeleteByTag(self::COMPOSITION_STALL_TAG);
	}

	/** Takes the summary of refusals off everyone who holds it. */
	public static function withdrawTransferRejectionsNotice(): bool
	{
		if (!Main\Loader::includeModule('im'))
		{
			return false;
		}

		return (bool)\CIMNotify::DeleteByTag(self::TRANSFER_REJECTIONS_TAG);
	}

	private static function getTransferRejectionsPart(string $code): string
	{
		return in_array($code, self::TRANSFER_REJECTION_HINT_CODES, true)
			? self::TRANSFER_REJECTIONS_HINTS
			: self::TRANSFER_REJECTIONS_REFUSALS
		;
	}

	/**
	 * Every part with anything in it under its heading, in the order of the parts.
	 *
	 * @param array<string, array{items: list<array>, rest: array<string, int>, count: int}> $parts by the prefix of
	 * their phrases, the names of the items escaped already
	 */
	private static function getTransferRejectionsMessage(array $parts, ?string $languageId): string
	{
		$lines = [];
		foreach ($parts as $part => ['items' => $items, 'rest' => $rest])
		{
			$partLines = self::getTransferRejectionsLines($items, $rest, $languageId);
			if ($partLines !== [])
			{
				$heading = self::TRANSFER_REJECTIONS_PHRASE . $part . 'HEADING';
				$lines[] = (string)Loc::getMessage($heading, null, $languageId);
				array_push($lines, ...$partLines);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * The named lines in the order given, then the tail of every code with something left.
	 *
	 * @param list<array{kind: string, name: string, code: string, count: int}> $items the names escaped already
	 * @param array<string, int> $rest
	 * @return list<string>
	 */
	private static function getTransferRejectionsLines(array $items, array $rest, ?string $languageId): array
	{
		$prefix = self::TRANSFER_REJECTIONS_PHRASE;
		$culture = Main\Context::getCurrent()->getCulture();
		$lines = [];
		foreach ($items as $item)
		{
			$code = strtoupper($item['code']);
			$line = match ($item['kind'])
			{
				'address' => Loc::getMessage($prefix . 'ADDRESS_' . $code, ['#EMAIL#' => $item['name']], $languageId),
				'domain' => Loc::getMessage($prefix . 'DOMAIN_' . $code, ['#DOMAIN#' => $item['name']], $languageId),
				'group' => Loc::getMessagePlural(
					$prefix . 'GROUP_' . $code,
					$item['count'],
					[
						'#DOMAIN#' => $item['name'],
						'#COUNT#' => number_format(
							$item['count'],
							0,
							$culture->getNumberDecimalSeparator(),
							$culture->getNumberThousandsSeparator(),
						),
					],
					$languageId,
				),
				default => null,
			};
			if ($line !== null)
			{
				$lines[] = (string)$line;
			}
		}
		foreach ($rest as $code => $count)
		{
			if ($count > 0)
			{
				$lines[] = (string)Loc::getMessagePlural(
					$prefix . 'MORE_' . strtoupper($code),
					$count,
					[
						'#COUNT#' => number_format(
							$count,
							0,
							$culture->getNumberDecimalSeparator(),
							$culture->getNumberThousandsSeparator(),
						),
					],
					$languageId,
				);
			}
		}

		return $lines;
	}

	public static function dispatchOrphanedMailboxAutoDisconnectNotifications(
		int $mailboxId,
		string $mailboxEmail,
	): void
	{
		$message = new OrphanedMailboxAutoDisconnectNotificationMessage(
			mailboxId: $mailboxId,
			mailboxEmail: $mailboxEmail,
		);

		$message->send('mail_orphan_autodisconnect_notification');
	}

	public static function notifyAdminAboutOrphanedMailboxAutoDisconnect(
		int $adminUserId,
		int $mailboxId,
		string $mailboxEmail,
	): void
	{
		$url = self::getMailboxEditUrl($mailboxId);
		$safeEmail = htmlspecialcharsbx($mailboxEmail);
		$emailWithLink = "[url={$url}]{$safeEmail}[/url]";

		$notifyMessage = self::getNotificationMessageCallback(
			'MAIL_NOTIFY_ORPHAN_MAILBOX_AUTODISCONNECT_7D_MESSAGE',
			['#EMAIL#' => $safeEmail],
		);
		$subject = self::getNotificationMessageCallback(
			'MAIL_NOTIFY_ORPHAN_MAILBOX_AUTODISCONNECT_7D_MESSAGE',
			['#EMAIL#' => $emailWithLink],
		);

		\CIMNotify::Add([
			'TO_USER_ID' => $adminUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|ORPHAN_AUTODISCONNECT_7D|' . $mailboxId,
			'NOTIFY_MESSAGE' => $notifyMessage,
			'PARAMS' => [
				'COMPONENT_ID' => 'MailEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
				],
			],
		]);
	}

	public static function dispatchScheduledMessageFailedNotification(
		int $scheduledMessageId,
		int $authorId,
		string $subject,
		string $errorCode,
		?string $viewUrl = null,
	): void
	{
		(new ScheduledMessageFailedMessage($scheduledMessageId, $authorId, $subject, $errorCode, $viewUrl))
			->send('mail_scheduled_message_failed')
		;
	}

	public static function notifyUserAboutScheduledMessageFailed(
		int $authorId,
		int $scheduledMessageId,
		string $subject,
		string $errorCode,
		?string $viewUrl = null,
	): void
	{
		// The mailbox the screen of the letter lives under is read here and not carried through the queue, so
		// messages already queued keep being processed by the shape they were sent with.
		if ($viewUrl === null)
		{
			$mailboxId = self::getScheduledMessageMailboxId($scheduledMessageId);
			$viewUrl = $mailboxId > 0 ? self::getScheduledMessageUrl($mailboxId, $scheduledMessageId) : '';
		}
		$messagePhrase = $viewUrl === ''
			? 'MAIL_NOTIFY_SCHEDULED_MESSAGE_FAILED'
			: 'MAIL_NOTIFY_SCHEDULED_MESSAGE_FAILED_WITH_LINK';

		\CIMNotify::Add([
			'TO_USER_ID' => $authorId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|SCHEDULED_MESSAGE|FAILED|' . $scheduledMessageId,
			// The reason is resolved inside the callback and not passed in ready: im renders the notification
			// in the language of its reader, and a text substituted beforehand would carry the language of
			// the agent that failed to send.
			'NOTIFY_MESSAGE' => static fn (?string $languageId = null) => Loc::getMessage(
				$messagePhrase,
				[
					'#SUBJECT#' => htmlspecialcharsbx($subject),
					'#ERROR#' => ScheduledMessageErrorText::getReason($errorCode, $languageId),
					'#VIEW_URL#' => $viewUrl,
				],
				$languageId,
			),
		]);
	}

	/** One notification per letter: a mailbox whose server keeps refusing does not repeat it on every retry. */
	public static function notifyUserAboutMessageSendFailed(
		int $authorId,
		int $messageId,
		string $subject,
		string $errorCode,
	): void
	{
		if ($authorId <= 0 || !Main\Loader::includeModule('im'))
		{
			return;
		}

		$viewUrl = $messageId > 0 ? htmlspecialcharsbx(sprintf('/mail/message/%u', $messageId)) : '';
		$messagePhrase = $viewUrl === ''
			? 'MAIL_NOTIFY_MESSAGE_SEND_FAILED'
			: 'MAIL_NOTIFY_MESSAGE_SEND_FAILED_WITH_LINK';

		\CIMNotify::Add([
			'TO_USER_ID' => $authorId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_TAG' => 'MAIL|MESSAGE|FAILED|' . $messageId,
			'NOTIFY_MESSAGE' => static fn (?string $languageId = null) => Loc::getMessage(
				$messagePhrase,
				[
					'#SUBJECT#' => htmlspecialcharsbx($subject),
					'#ERROR#' => SendFailure::getText($errorCode, $languageId),
					'#VIEW_URL#' => $viewUrl,
				],
				$languageId,
			),
		]);
	}

	/** Zero for a letter that is no longer there: a dead address in the notification is worse than none. */
	private static function getScheduledMessageMailboxId(int $scheduledMessageId): int
	{
		if ($scheduledMessageId <= 0)
		{
			return 0;
		}

		$row = ScheduledMessageTable::query()
			->setSelect(['MAILBOX_ID'])
			->where('ID', $scheduledMessageId)
			->setLimit(1)
			->fetch()
		;

		return (int)($row['MAILBOX_ID'] ?? 0);
	}
}

