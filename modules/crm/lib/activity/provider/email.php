<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Crm;
use Bitrix\Crm\Activity;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Activity\CommunicationStatistics;
use Bitrix\Crm\Automation\Trigger\EmailSentTrigger;
use Bitrix\Crm\Timeline\LogMessageType;
use Bitrix\Crm\Update\Activity\CompressMailStepper;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Message;
use Bitrix\Main\Config;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class Email extends Activity\Provider\Base
{
	/**
	 * Size of html description can cause long sanitizing
	 */
	public const HTML_SIZE_LONG_SANITIZE_THRESHOLD = 50000;

	public const ERROR_TYPE_PARTIAL = "partial";
	public const ERROR_TYPE_FULL = "full";

	protected const TYPE_EMAIL = 'EMAIL';
	public const TYPE_EMAIL_COMPRESSED = 'EMAIL_COMPRESSED';
	private const DESCRIPTION_PREVIEW_LIMIT = 200;

	public static function getId()
	{
		return 'CRM_EMAIL';
	}

	public static function getTypeId(array $activity)
	{
		if (isset($activity['PROVIDER_TYPE_ID']) && $activity['PROVIDER_TYPE_ID'] === self::TYPE_EMAIL_COMPRESSED)
		{
			return self::TYPE_EMAIL_COMPRESSED;
		}

		return self::TYPE_EMAIL;
	}

	public static function getTypes()
	{
		return [
			[
				'NAME' => 'E-mail',
				'PROVIDER_ID' => static::getId(),
				'PROVIDER_TYPE_ID' => self::TYPE_EMAIL,
			],
			[
				'NAME' => 'E-mail (Compressed)',
				'PROVIDER_ID' => static::getId(),
				'PROVIDER_TYPE_ID' => self::TYPE_EMAIL_COMPRESSED,
			],
		];
	}

	/**
	 * Format email quote for answer editor
	 *
	 * @param array $activityFields Fields of activity
	 * @param string $quotedText Html text of previous email
	 * @param bool $uncompressed Is activity fields were uncompressed already
	 * @param bool $sanitized Is quited text sanitized already
	 *
	 * @return string
	 */
	public static function getMessageQuote(
		array $activityFields,
		string $quotedText,
		bool $uncompressed = false,
		bool $sanitized = false,
	): string
	{
		if (!Loader::includeModule('mail'))
		{
			return '';
		}

		if (!$uncompressed)
		{
			static::uncompressActivityDescription($activityFields);
		}
		$header = Activity\Mail\Message::getHeader([
			'OWNER_TYPE_ID' => (int)$activityFields['OWNER_TYPE_ID'],
			'OWNER_ID' => (int)$activityFields['OWNER_ID'],
			'ID' => $activityFields['ID'],
			'SETTINGS' => $activityFields['SETTINGS'],
		], false)->getData();

		return Message::wrapTheMessageWithAQuote(
			$quotedText,
			$activityFields['SUBJECT'] ?? '',
			$activityFields['START_TIME'] ?? null,
			$header['from'],
			$header['to'],
			$header['cc'],
			$sanitized,
		);
	}

	public static function getName()
	{
		return 'E-mail';
	}

	public static function getTypeName($providerTypeId = null, $direction = \CCrmActivityDirection::Undefined)
	{
		return Loc::getMessage('CRM_ACTIVITY_PROVIDER_EMAIL_NAME');
	}

	public static function getCommunicationType($providerTypeId = null)
	{
		return static::COMMUNICATION_TYPE_EMAIL;
	}

	/**
	 * @param null|string $providerTypeId Provider type id.
	 * @return bool
	 */
	public static function canUseLiveFeedEvents($providerTypeId = null)
	{
		return true;
	}

	/**
	 * @param array $activity Activity data.
	 * @return bool
	 */
	public static function checkForWaitingCompletion(array $activity)
	{
		$completed = isset($activity['COMPLETED']) && $activity['COMPLETED'] === 'Y';
		$incoming = isset($activity['DIRECTION']) && $activity['DIRECTION'] == \CCrmActivityDirection::Incoming;

		return !$completed || $incoming;
	}

	/**
	 * @param null|string $providerTypeId Provider type id.
	 * @param int $direction Activity direction.
	 * @return bool
	 */
	public static function isTypeEditable($providerTypeId = null, $direction = \CCrmActivityDirection::Undefined)
	{
		return false;
	}

	public static function getSupportedCommunicationStatistics()
	{
		return [
			CommunicationStatistics::STATISTICS_QUANTITY,
		];
	}

	public static function checkFields($action, &$fields, $id, $params = null)
	{
		$result = new \Bitrix\Main\Result();

		if (isset($fields['END_TIME']) && $fields['END_TIME'] != '')
		{
			$fields['DEADLINE'] = $fields['END_TIME'];
		}
		elseif (isset($fields['~END_TIME']) && $fields['~END_TIME'] !== '')
		{
			$fields['~DEADLINE'] = $fields['~END_TIME'];
		}

		return $result;
	}

	public static function onAfterAdd($activityFields, array $params = null)
	{
		$direction = isset($activityFields['DIRECTION']) ? (int)$activityFields['DIRECTION'] : \CCrmActivityDirection::Undefined;

		if ($direction === \CCrmActivityDirection::Outgoing)
		{
			EmailSentTrigger::execute($activityFields['BINDINGS'], $activityFields);
		}

		if ($direction === \CCrmActivityDirection::Outgoing)
		{
			$itemIdentifier = Crm\ItemIdentifier::createFromArray($activityFields);
			if ($itemIdentifier)
			{
				$badge = Crm\Service\Container::getInstance()->getBadge(
					Crm\Badge\Badge::MAIL_MESSAGE_DELIVERY_STATUS_TYPE,
					Crm\Badge\Type\MailMessageDeliveryStatus::MAIL_MESSAGE_DELIVERY_ERROR_VALUE,
				);
				$badge->deleteByEntity($itemIdentifier, $badge->getType(), $badge->getValue());
			}
		}
	}

	public static function renderView(array $activity)
	{
		global $APPLICATION;

		ob_start();

		$APPLICATION->IncludeComponent(
			'bitrix:crm.activity.email', '',
			[
				'ACTIVITY' => $activity,
				'ACTION'   => 'view',
			]
		);

		return ob_get_clean();
	}

	public static function renderEdit(array $activity)
	{
		global $APPLICATION;

		ob_start();

		$APPLICATION->IncludeComponent(
			'bitrix:crm.activity.email', '',
			[
				'ACTIVITY' => $activity,
				'ACTION'   => 'create',
			]
		);

		return ob_get_clean();
	}

	public static function prepareEmailInfo(array $fields)
	{
		$direction = isset($fields['DIRECTION']) ? (int)$fields['DIRECTION'] : \CCrmActivityDirection::Undefined;
		if ($direction !== \CCrmActivityDirection::Outgoing)
		{
			return null;
		}

		$settings = isset($fields['SETTINGS'])
			? (is_array($fields['SETTINGS']) ? $fields['SETTINGS'] : unserialize($fields['SETTINGS'], ['allowed_classes' => false]))
			: [];
		if (!(isset($settings['IS_BATCH_EMAIL']) && $settings['IS_BATCH_EMAIL'] === false))
		{
			return null;
		}


		if (isset($settings['READ_CONFIRMED']) && $settings['READ_CONFIRMED'] > 0)
		{
			return [
				"STATUS_ERROR" => false,
				"STATUS_TEXT" => Loc::getMessage('CRM_ACTIVITY_PROVIDER_EMAIL_STATUS_READ')
			];
		}

		switch (($settings["SENT_ERROR"] ?? null))
		{
			case self::ERROR_TYPE_FULL:
				return array(
					"STATUS_ERROR" => true,
					"STATUS_TEXT" => Loc::getMessage('CRM_ACTIVITY_PROVIDER_EMAIL_STATUS_ERROR')
				);
			case self::ERROR_TYPE_PARTIAL:
				return array(
					"STATUS_ERROR" => false,
					"STATUS_TEXT" => Loc::getMessage('CRM_ACTIVITY_PROVIDER_EMAIL_STATUS_SENT_WITH_ERROR')
				);
			default:
				return Config\Option::get('main', 'track_outgoing_emails_read', 'Y') != 'Y'? null : array(
					"STATUS_ERROR" => false,
					"STATUS_TEXT" => Loc::getMessage('CRM_ACTIVITY_PROVIDER_EMAIL_STATUS_SENT')
				);
		}
	}

	public static function getParentByEmail(&$msgFields)
	{
		$inReplyTo = isset($msgFields['IN_REPLY_TO']) ? $msgFields['IN_REPLY_TO'] : '';

		// @TODO: multiple
		if (!empty($inReplyTo))
		{
			if (preg_match('/<crm\.activity\.((\d+)-[0-9a-z]+)@[^>]+>/i', sprintf('<%s>', $inReplyTo), $matches))
			{
				$matchActivity = \CCrmActivity::getById($matches[2], false);
				if ($matchActivity && mb_strtolower($matchActivity['URN']) == mb_strtolower($matches[1]))
					$targetActivity = $matchActivity;
			}

			if (empty($targetActivity))
			{
				$res = Activity\MailMetaTable::getList([
					'select' => ['ACTIVITY_ID'],
					'filter' => [
						'=MSG_ID_HASH' => md5(mb_strtolower($inReplyTo)),
					],
				]);

				while ($mailMeta = $res->fetch())
				{
					if ($matchActivity = \CCrmActivity::getById($mailMeta['ACTIVITY_ID'], false))
					{
						$targetActivity = $matchActivity;
						break;
					}
				}
			}
		}

		if (empty($targetActivity))
		{
			$urnInfo = \CCrmActivity::parseUrn(
				\CCrmActivity::extractUrnFromMessage(
					$msgFields, \CCrmEMailCodeAllocation::getCurrent()
				)
			);

			if ($urnInfo['ID'] > 0)
			{
				$matchActivity = \CCrmActivity::getById($urnInfo['ID'], false);
				if (!empty($matchActivity) && mb_strtolower($matchActivity['URN']) == mb_strtolower($urnInfo['URN']))
					$targetActivity = $matchActivity;
			}
		}

		if (!empty($targetActivity))
		{
			if ($targetActivity['OWNER_TYPE_ID'] > 0 && $targetActivity['OWNER_ID'] > 0)
			{
				return $targetActivity;
			}
		}

		return false;
	}

	public static function compressActivityDescription(array &$activity): void
	{
		$activity['PROVIDER_ID'] = self::getId();
		$activity['PROVIDER_TYPE_ID'] = self::TYPE_EMAIL_COMPRESSED;
		$activity['DESCRIPTION'] = self::makeDescriptionPreview(
			$activity['DESCRIPTION'] ?? '',
			(int)($activity['DESCRIPTION_TYPE'] ?? \CCrmContentType::Html),
		);
	}

	private static function makeDescriptionPreview(string $description, int $descriptionType): string
	{
		$fields = [
			'DESCRIPTION' => $description,
			'DESCRIPTION_TYPE' => $descriptionType,
		];

		\CCrmActivity::PrepareDescriptionFields(
			$fields,
			[
				'ENABLE_HTML' => false,
				'ENABLE_BBCODE' => false,
				'LIMIT' => self::DESCRIPTION_PREVIEW_LIMIT,
			],
		);

		return $fields['DESCRIPTION_RAW'] ?? '';
	}

	public static function addMailBodyBinding(int $activityId, int $ownerTypeId, string $description): void
	{
		$bodyId = Crm\Activity\MailBodyTable::addByBody($description);

		Activity\Entity\ActMailBodyBindTable::add([
			'BODY_ID' => (int)$bodyId,
			'OWNER_TYPE_ID' => $ownerTypeId,
			'OWNER_ID' => $activityId,
		]);
	}

	public static function uncompressActivityDescription(array &$activity): void
	{
		if (
			!isset($activity['PROVIDER_TYPE_ID'])
			|| $activity['PROVIDER_TYPE_ID'] !== self::TYPE_EMAIL_COMPRESSED
		)
		{
			return;
		}

		// Handles case when stepper hasn't yet converted all mails to new compressed format
		if (
			isset($activity['ASSOCIATED_ENTITY_ID'])
			&& (int)$activity['ASSOCIATED_ENTITY_ID'] > 0
			&& Config\Option::get('crm', CompressMailStepper::COMPRESS_IN_PROGRESS_OPTION_NAME, 'N') === 'Y'
		)
		{
			$bodyId = (int)$activity['ASSOCIATED_ENTITY_ID'];

			$body = Crm\Activity\MailBodyTable::getById($bodyId)->fetch();
			if ($body)
			{
				$activity['DESCRIPTION'] = $body['BODY'];
			}

			return;
		}

		$mailBodyBind = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['BODY_ID'])
			->where('OWNER_TYPE_ID', \CCrmOwnerType::Activity)
			->where('OWNER_ID', (int)$activity['ID'])
			->setLimit(1)
			->fetchObject()
		;

		$bodyId = $mailBodyBind ? (int)($mailBodyBind->getBodyId()) : 0;

		if ($bodyId > 0)
		{
			$body = Crm\Activity\MailBodyTable::getById($bodyId)->fetch();
			if ($body && $body['BODY'] !== '')
			{
				$activity['DESCRIPTION'] = $body['BODY'];

				return;
			}
		}

		self::restoreBodyFromMailMessage($activity, $bodyId);
	}

	protected static function restoreBodyFromMailMessage(array &$activity, int $bodyId): void
	{
		$activityId = (int)($activity['ID'] ?? 0);
		$messageId = (int)($activity['UF_MAIL_MESSAGE'] ?? 0);
		if ($activityId <= 0 || $messageId <= 0)
		{
			return;
		}

		$settings = $activity['SETTINGS'] ?? [];
		if (!empty($settings[\CCrmEMail::ACTIVITY_SETTINGS_MAIL_BODY_RESTORE_CHECKED_FIELD]))
		{
			return;
		}

		if (!Loader::includeModule('mail'))
		{
			return;
		}

		$message = \CMailMessage::getById($messageId)->fetch();
		if (empty($message))
		{
			self::markMailBodyRestoreChecked($activityId, $activity, $settings);

			return;
		}

		if (empty($message['BODY']) && empty($message['BODY_HTML']))
		{
			$messageOptions = $message['OPTIONS'] ?? [];

			// Body was intentionally cleared by mail module (visually empty HTML) —
			// resync won't help, this is not a download error
			if (!empty($messageOptions['isStrippedTags']))
			{
				self::markMailBodyRestoreChecked($activityId, $activity, $settings);

				return;
			}

			$mailboxId = (int)($message['MAILBOX_ID'] ?? 0);
			if ($mailboxId <= 0)
			{
				self::markMailBodyRestoreChecked($activityId, $activity, $settings);

				return;
			}

			if (self::isMailMessageBodyAlreadySynced($mailboxId, $messageId))
			{
				self::markMailBodyRestoreChecked($activityId, $activity, $settings);

				return;
			}

			\Bitrix\Mail\Helper\Message::reSyncBody($mailboxId, [$messageId]);
			$message = \CMailMessage::getById($messageId)->fetch();
		}

		if (empty($message))
		{
			self::markMailBodyRestoreChecked($activityId, $activity, $settings);

			return;
		}

		$description = (new Activity\Mail\Attachment\MailActivityDescriptionFactory())
			->makeFromMessageFieldsArray($message)
		;

		if (empty($description->description))
		{
			self::markMailBodyRestoreChecked($activityId, $activity, $settings);

			return;
		}

		if ($bodyId > 0)
		{
			self::updateMailBody($activityId, $bodyId, $description->description);
		}
		else
		{
			self::addMailBodyBinding($activityId, \CCrmOwnerType::Activity, $description->description);
		}
		$settings[\CCrmEMail::ACTIVITY_SETTINGS_MAIL_BODY_RESTORE_CHECKED_FIELD] = 1;
		$settings['SANITIZE_ON_VIEW'] = (int)($message['SANITIZE_ON_VIEW'] ?? 0);

		ActivityTable::update(
			$activityId,
			[
				'SETTINGS' => $settings,
				'DESCRIPTION' => self::makeDescriptionPreview($description->description, \CCrmContentType::Html),
			],
		);

		$activity['DESCRIPTION'] = $description->description;
		$activity['SETTINGS'] = $settings;
	}

	private static function markMailBodyRestoreChecked(int $activityId, array &$activity, array $settings): void
	{
		if ($activityId <= 0)
		{
			return;
		}

		$settings[\CCrmEMail::ACTIVITY_SETTINGS_MAIL_BODY_RESTORE_CHECKED_FIELD] = 1;

		ActivityTable::update(
			$activityId,
			['SETTINGS' => $settings],
		);

		$activity['SETTINGS'] = $settings;
	}

	private static function updateMailBody(int $activityId, int $oldBodyId, string $description): void
	{
		$newBodyId = Crm\Activity\MailBodyTable::addByBody($description);

		if ($newBodyId === $oldBodyId)
		{
			return;
		}

		$bind = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['ID'])
			->where('OWNER_TYPE_ID', \CCrmOwnerType::Activity)
			->where('OWNER_ID', $activityId)
			->where('BODY_ID', $oldBodyId)
			->setLimit(1)
			->fetchObject()
		;

		if ($bind)
		{
			Activity\Entity\ActMailBodyBindTable::update($bind->getId(), [
				'BODY_ID' => $newBodyId,
			]);
		}
	}

	private static function isMailMessageBodyAlreadySynced(int $mailboxId, int $messageId): bool
	{
		$unSyncOption = MailEntityOptionsTable::getList([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => 'MESSAGE',
				'=ENTITY_ID' => $messageId,
				'=PROPERTY_NAME' => 'UNSYNC_BODY',
			],
			'limit' => 1,
		])->fetch();

		return !$unSyncOption || $unSyncOption['VALUE'] !== 'Y';
	}

	public static function getBodyByMailId(int $id): ?\Bitrix\Crm\Activity\EO_MailBody
	{
		$mailBodyBind = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['BODY_ID'])
			->where('OWNER_ID', $id)
			->setLimit(1)
			->fetchObject()
		;

		$bodyId = $mailBodyBind ? (int)$mailBodyBind->getBodyId() : 0;

		$body = Crm\Activity\MailBodyTable::getById($bodyId)->fetchObject();

		return $body === false ? null : $body;
	}

	public static function doesBodyHaveExactlyOneBinding(int $bodyId): bool
	{
		$bindings = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['ID'])
			->where('BODY_ID', $bodyId)
			->setLimit(2)
			->fetchAll()
		;

		$count = count($bindings);

		return $count === 1;
	}

	public static function deleteBindingByMailId(int $id, bool $withBody = false): void
	{
		$mailBodyBind = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['ID', 'BODY_ID'])
			->where('OWNER_ID', $id)
			->setLimit(1)
			->fetchObject()
		;

		if ($mailBodyBind)
		{
			$bindingId = (int)$mailBodyBind->getId();
			$bodyId = (int)$mailBodyBind->getBodyId();

			Activity\Entity\ActMailBodyBindTable::delete($bindingId);

			if ($withBody)
			{
				Crm\Activity\MailBodyTable::delete($bodyId);
			}
		}
	}

	public static function onAfterDelete(
		int $id,
		array $activityFields,
		?array $params = null,
	): void
	{
		if (
			\Bitrix\Crm\Recycling\ActivityController::isEnabled()
			&& \Bitrix\Crm\Settings\ActivitySettings::getCurrent()->isRecycleBinEnabled()
		)
		{
			self::suspendMailBodyBind($id);
		}
		else
		{
			self::deleteMailBodyBind($id);
		}

		parent::onAfterDelete($id, $activityFields, $params);
	}

	public static function processRestorationFromRecycleBin(array $activityFields, ?array $params = null): Result
	{
		$oldEntityID = (int)$activityFields['THREAD_ID'];
		$newEntityID = \CCrmActivity::Add(
			$activityFields,
			false,
			false,
			[
				'IS_RESTORATION' => true,
				'MOVED_TO_BIN_DATETIME' => $params['DATETIME'] ?? null,
				'DISABLE_USER_FIELD_CHECK' => true,
			],
		);

		$bindingId = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['ID'])
			->where('OWNER_TYPE_ID', \CCrmOwnerType::SuspendedActivity)
			->where('OWNER_ID', $oldEntityID)
			->setLimit(1)
			->fetchObject()
			?->getId() ?? 0
		;

		Activity\Entity\ActMailBodyBindTable::update(
			$bindingId,
			[
				'OWNER_TYPE_ID' => \CCrmOwnerType::Activity,
				'OWNER_ID' => $newEntityID,
			],
		);

		return (new Result())
			->setData(['entityId' => $newEntityID])
		;
	}

	public static function onAfterRecycleBinErase(array $activityFields, ?array $params = null): void
	{
		$id = (int)$activityFields['ID'];
		self::deleteMailBodyBind($id);
	}

	protected static function suspendMailBodyBind(int $mailId): void
	{
		$bindingId = Activity\Entity\ActMailBodyBindTable::query()
			->setSelect(['ID'])
			->where('OWNER_TYPE_ID', \CCrmOwnerType::Activity)
			->where('OWNER_ID', $mailId)
			->setLimit(1)
			->fetchObject()
			?->getId() ?? 0
		;
		Activity\Entity\ActMailBodyBindTable::update(
			$bindingId,
			[
				'OWNER_TYPE_ID' => Crm\Recycling\ActivityController::getInstance()?->getSuspendedEntityTypeID()
					?? \CCrmOwnerType::SuspendedActivity,
			],
		);
	}

	protected static function deleteMailBodyBind(int $mailId): void
	{
		$body = self::getBodyByMailId($mailId);
		$bodyId = $body?->getId() ?? 0;
		$withBody = self::doesBodyHaveExactlyOneBinding($bodyId);

		self::deleteBindingByMailId($mailId, $withBody);
	}

	/**
	 * Sanitize email message body html
	 *
	 * @param string $html Raw html of email body
	 *
	 * @return string
	 */
	protected static function sanitizeBody(string $html): string
	{
		if (IsModuleInstalled('mail') && Loader::includeModule('mail'))
		{
			return \Bitrix\Mail\Helper\Message::sanitizeHtml($html, true);
		}
		$sanitizer = new \CBXSanitizer();
		$sanitizer->setLevel(\CBXSanitizer::SECURE_LEVEL_LOW);
		$sanitizer->applyDoubleEncode(false);
		$sanitizer->addTags(['style' => []]);

		return $sanitizer->sanitizeHtml($html);
	}

	/**
	 * Get description html according to description type
	 *
	 * @param string $description Description text
	 * @param int $type Description type
	 * @param bool $needSanitize Is need to sanotize html tags
	 *
	 * @return string
	 */
	public static function getDescriptionHtmlByType(string $description, int $type, bool $needSanitize): string
	{
		return match ($type)
		{
			\CCrmContentType::BBCode => (new \CTextParser())->convertText($description),
			\CCrmContentType::Html => ($needSanitize) ? static::sanitizeBody($description) : $description,
			default => preg_replace('/[\r\n]+/u',
				'<br>',
				htmlspecialcharsbx($description)
			),
		};
	}

	/**
	 * Get description field from activity fields
	 *
	 * @param array $activity Activity fields data
	 *
	 * @return string
	 */
	public static function getDescriptionHtmlByActivityFields(array $activity): string
	{
		$description = (string)($activity['DESCRIPTION'] ?? '');
		$type = (int)($activity['DESCRIPTION_TYPE'] ?? \CCrmContentType::PlainText);
		$needSanitize = (bool)($activity['SETTINGS']['SANITIZE_ON_VIEW'] ?? false);

		return self::getDescriptionHtmlByType($description, $type, $needSanitize);
	}

	/**
	 * Is sanitizing can be long?
	 *
	 * @param array $activity Activity fields data
	 *
	 * @return bool
	 */
	public static function isSanitizingCanBeLong(array $activity): bool
	{
		$description = (string)($activity['DESCRIPTION'] ?? '');
		$type = (int)($activity['DESCRIPTION_TYPE'] ?? \CCrmContentType::PlainText);
		$needSanitize = (bool)($activity['SETTINGS']['SANITIZE_ON_VIEW'] ?? false);

		return $needSanitize
			&& $type === \CCrmContentType::Html
			&& mb_strlen(trim($description)) > self::HTML_SIZE_LONG_SANITIZE_THRESHOLD;
	}

	/**
	 * Get fast process fallback for description, instead of sanitize
	 *
	 * @param string $description Html description
	 *
	 * @return string
	 */
	public static function getFallbackHtmlDescription(string $description): string
	{
		$textLikeTextBody = html_entity_decode(htmlToTxt($description), ENT_QUOTES | ENT_HTML401);

		return preg_replace('/(\s*(\r\n|\n|\r))+/', '<br>', htmlspecialcharsbx($textLikeTextBody));
	}

	public static function getMoveBindingsLogMessageType(): ?string
	{
		return LogMessageType::EMAIL_INCOMING_MOVED;
	}
}
