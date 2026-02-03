<?php

namespace Bitrix\Mail\Integration\Im;

use Bitrix\Mail\Helper\AnalyticsHelper;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Web\Uri;
use \Bitrix\Mail\Internals\MailboxAccessTable;
use \Bitrix\Mail\MailboxTable;

Loc::loadMessages(__FILE__);

class Notification
{
	const notifierSchemeTypeMail = 'new_message_v2';
	const notifierSchemeTypeMailTariffRestrictions = 'tariff_restrictions';
	const notifyPushTagMailMessage = 'MAIL|MESSAGE|%u';
	const notifyPushTagMailList = 'MAIL|LIST|%u';

	//region General methods

	public static function getSchema()
	{
		Main\Loader::includeModule('im');

		return [
			'mail' => [
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
				[ '#COUNT#' => $messageCount ],
				$languageId,
			);
		}

		if ($message['SUBJECT'])
		{
			return static fn (?string $languageId = null) => Loc::getMessage(
				'MAIL_PUSH_NOTIFY_NEW_SINGLE_MESSAGE_IN_MAIL_CLIENT',
				[ '#SUBJECT#' => $message['SUBJECT'] ],
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

		return sprintf(self::notifyPushTagMailMessage, $message['ID']);
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

		\CIMNotify::add([
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'mail',
			'NOTIFY_EVENT' => self::notifierSchemeTypeMail,
			'NOTIFY_TITLE' => $notifyTitleCallback,
			'PUSH_PARAMS' => [
				'ACTION' => 'mail',
				'TAG' => self::getPushTagForNewMessage($message, $fields['mailboxId']),
				'ADVANCED_PARAMS' => [
					'id' => 'im_notify',
					'group' => 'im_notify',
					'senderName' => Loc::getMessage('MAIL_NOTIFY_NEW_MESSAGE_TITLE'),
				],
				['TAG' => 'IM_NOTIFY']
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
			else if ($type == 'imposed_tariff_restrictions_on_the_mailbox')
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
		$mailboxUrl = "/mail/config/edit?id=$mailboxId";
		$plainMailboxEmail = $mailbox['EMAIL'];

		$safeMailboxEmail = htmlspecialcharsbx($plainMailboxEmail);
		$mailboxWithBBCode = "[url={$mailboxUrl}] {$safeMailboxEmail} [/url]";

		if ($hasOwnerChanged && $editorUserId !== $originalOwnerId)
		{
			self::notifyOriginalOwnerAboutOwnershipChange($originalOwnerId, $editorUserId, $safeMailboxEmail);
		}

		if ($hasOwnerChanged && $editorUserId !== $finalOwnerId)
		{
			self::notifyFinalOwnerAboutOwnershipChange($finalOwnerId, $editorUserId, $safeMailboxEmail);
		}

		if ($editorUserId !== $finalOwnerId)
		{
			self::notifyFinalOwnerAboutSettingsChange($finalOwnerId, $editorUserId, $mailboxWithBBCode);
		}
	}

	public static function notifyOriginalOwnerAboutOwnershipChange(
		int $originalOwnerId,
		int $editorUserId,
		string $mailboxEmail,
	): void
	{
		$replacements = ['#EMAIL#' => $mailboxEmail];

		$notifyMessage = self::getNotificationMessageCallback(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_FROM_NOTIFY_MESSAGE',
			$replacements,
		);
		$subject = self::getNotificationMessageCallback(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_FROM_NOTIFY_MESSAGE_PARAMS',
			$replacements,
		);
		$plainText = self::getNotificationMessageCallback(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_FROM_NOTIFY_MESSAGE_PARAMS_PLAIN_TEXT',
		);

		\CIMNotify::Add([
			"TO_USER_ID" => $originalOwnerId,
			"NOTIFY_TYPE" => IM_NOTIFY_FROM,
			"FROM_USER_ID" => $editorUserId,
			"NOTIFY_MODULE" => 'mail',
			"NOTIFY_MESSAGE" => $notifyMessage,
			"PARAMS" => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
					'PLAIN_TEXT' => $plainText,
				],
			],
		]);
	}

	public static function notifyFinalOwnerAboutOwnershipChange(
		int $finalOwnerId,
		int $editorUserId,
		string $mailboxWithBBCode,
	): void
	{
		$replacements = ['#EMAIL#' => $mailboxWithBBCode];

		$notifyMessage = self::getNotificationMessageCallback(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_TO_NOTIFY_MESSAGE',
			$replacements,
		);
		$subject = self::getNotificationMessageCallback(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_TO_NOTIFY_MESSAGE_PARAMS',
			$replacements,
		);
		$plainText = self::getNotificationMessageCallback(
			'MAIL_CLIENT_CONFIG_OWNER_CHANGE_TO_NOTIFY_MESSAGE_PARAMS_PLAIN_TEXT',
		);

		\CIMNotify::Add([
			"TO_USER_ID" => $finalOwnerId,
			"NOTIFY_TYPE" => IM_NOTIFY_FROM,
			"FROM_USER_ID" => $editorUserId,
			"NOTIFY_MODULE" => 'mail',
			"NOTIFY_MESSAGE" => $notifyMessage,
			"PARAMS" => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
					'PLAIN_TEXT' => $plainText,
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
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subject,
				],
			],
		]);
	}

	public static function getNotificationMessageCallback(string $messageCode, array $replacements = []): callable
	{
		return fn (?string $languageId = null) => Loc::getMessage(
			$messageCode,
			$replacements,
			$languageId,
		);
	}
}
