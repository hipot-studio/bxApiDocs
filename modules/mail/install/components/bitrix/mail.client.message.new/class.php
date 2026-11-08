<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail;
use Bitrix\Mail\Integration\AI;

Loc::loadMessages(__DIR__ . '/../mail.client/class.php');

Main\Loader::includeModule('mail');

class CMailClientMessageNewComponent extends CBitrixComponent
{
	/** Template of the redesigned form: only it gets the initial data of TPL-01. */
	private const COMPOSE_TEMPLATE_NAME = 'compose';

	/** Statuses ScheduledMessageService still accepts a change of: everything else is refused on the way in. */
	private const EDITABLE_SCHEDULED_STATUSES = [
		Mail\Internals\ScheduledMessageTable::STATUS_SCHEDULED,
		Mail\Internals\ScheduledMessageTable::STATUS_ERROR,
	];

	private const SCHEDULED_STATUS_REFUSAL_PHRASES = [
		Mail\Internals\ScheduledMessageTable::STATUS_SENDING => 'MAIL_MESSAGE_NEW_SCHEDULED_SENDING',
		Mail\Internals\ScheduledMessageTable::STATUS_SENT => 'MAIL_MESSAGE_NEW_SCHEDULED_SENT',
		Mail\Internals\ScheduledMessageTable::STATUS_CANCELLED => 'MAIL_MESSAGE_NEW_SCHEDULED_CANCELLED',
	];

	/** @var bool */
	private $isCrmEnable = false;

	/**
	 * @return mixed|void
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function executeComponent($level = 1)
	{
		global $USER, $APPLICATION;

		if (!is_object($USER) || !$USER->isAuthorized())
		{
			$APPLICATION->authForm('');

			return;
		}

		$draftId = max(0, (int)($_REQUEST['draftId'] ?? 0));
		$scheduledMessageId = max(0, (int)($_REQUEST['scheduledId'] ?? 0));
		$scheduledMessage = null;
		if ($scheduledMessageId > 0)
		{
			// A saved link outlives the feature: without this, the form of a new letter would open with empty
			// fields and no word said, and the author would take it for the letter they came to change.
			if (!Mail\Helper\Config\Feature::isScheduledMessageAvailable())
			{
				showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_DENIED'));

				return;
			}

			$result = (new Mail\Service\ScheduledMessage\ScheduledMessageService())->get(
				(int)$USER->getId(),
				$scheduledMessageId,
			);
			$scheduledMessage = $result->getData()['scheduledMessage'] ?? null;
			if (
				!$result->isSuccess()
				|| !is_array($scheduledMessage)
				|| (int)($scheduledMessage['AUTHOR_ID'] ?? 0) !== (int)$USER->getId()
			)
			{
				showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_DENIED'));

				return;
			}

			$statusRefusal = self::getScheduledStatusRefusal((string)($scheduledMessage['STATUS'] ?? ''));
			if ($statusRefusal !== null)
			{
				showError($statusRefusal);

				return;
			}
		}
		$isDraftParentMessage = false;
		$messageId = 0;
		$isReplyAll = false;
		if (!empty($_REQUEST['forward']) && $_REQUEST['forward'] > 0)
		{
			$messageType = 'forward';
			$messageId = (int)$_REQUEST['forward'];
			$subjectPrefix = 'Fwd';
		}
		elseif (!empty($_REQUEST['reply']) && $_REQUEST['reply'] > 0)
		{
			$messageType = 'reply';
			$messageId = (int)$_REQUEST['reply'];
			$subjectPrefix = 'Re';
			$isReplyAll = isset($_REQUEST['reply_all']) && $_REQUEST['reply_all'] === 'Y';
		}
		elseif ($draftId > 0 && Mail\Helper\Config\Feature::isInternalDraftsWebAvailable())
		{
			$draft = (new Mail\Internal\Repository\DraftRepository())->findActiveById(
				(int)$USER->getId(),
				$draftId,
				Mail\Internals\DraftTable::CONTEXT_MAIL,
			);
			$draftMode = $draft?->snapshot->mode;
			$draftParentMessageId = (int)($draft?->snapshot->parentMessageId ?? 0);
			if (in_array($draftMode, ['reply', 'forward'], true) && $draftParentMessageId > 0)
			{
				$isDraftParentMessage = true;
				$messageType = $draftMode;
				$messageId = $draftParentMessageId;
				$subjectPrefix = $draftMode === 'reply' ? 'Re' : 'Fwd';
			}
		}

		$message = [];

		$this->arResult['TO_PLUG_EXTENSION_SALES_LETTER_TEMPLATE'] = false;

		if (!empty($_REQUEST['sales_letter']) && $_REQUEST['sales_letter'])
		{
			$this->arResult['TO_PLUG_EXTENSION_SALES_LETTER_TEMPLATE'] = true;
		}

		if (!empty($_REQUEST['id']) && $_REQUEST['id'] > 0)
		{
			if ($mailbox = Mail\MailboxTable::getUserMailbox($_REQUEST['id']))
			{
				$message = [
					'MAILBOX_ID' => $mailbox['ID'],
					'MAILBOX_EMAIL' => $mailbox['EMAIL'],
					'MAILBOX_NAME' => 'MAILBOX.NAME',
					'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				];
			}
		}
		elseif (is_array($scheduledMessage))
		{
			$mailboxId = (int)($scheduledMessage['MAILBOX_ID'] ?? 0);
			if ($mailbox = Mail\MailboxTable::getUserMailbox($mailboxId))
			{
				$message = [
					'MAILBOX_ID' => $mailbox['ID'],
					'MAILBOX_EMAIL' => $mailbox['EMAIL'],
					'MAILBOX_NAME' => 'MAILBOX.NAME',
					'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				];
			}
		}

		if ($messageId > 0)
		{
			$message = $this->getQuotedMessage($messageId);

			if (empty($message))
			{
				if (!$isDraftParentMessage)
				{
					showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_NOT_FOUND'));

					return;
				}

				$messageId = 0;
			}
			elseif (!Mail\Helper\Message::hasAccess($message))
			{
				if (!$isDraftParentMessage)
				{
					showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_DENIED'));

					return;
				}

				$message = [];
				$messageId = 0;
			}

			if ($messageId > 0)
			{
				$message['ORIGINAL_SUBJECT'] = $message['SUBJECT'];
				if (!empty($subjectPrefix))
				{
					$message['SUBJECT'] = preg_replace(
						sprintf('/^(%s:\s*)?/i', preg_quote($subjectPrefix)),
						sprintf('%s: ', $subjectPrefix),
						$message['SUBJECT'],
					);
				}

				if ($level <= 1 && Mail\Helper\Message::ensureAttachments($message) > 0)
				{
					return $this->executeComponent($level + 1);
				}

				$message['__files'] = [];
				if ($message['ATTACHMENTS'] > 0)
				{
					$message['__files'] = Mail\Internals\MailMessageAttachmentTable::getList([
						'select' => [
							'ID', 'FILE_ID', 'FILE_NAME', 'FILE_SIZE', 'CONTENT_TYPE',
						],
						'filter' => [
							'=MESSAGE_ID' => $message['ID'],
						],
					])->fetchAll();
				}

				$message['ID'] = 0;

				$message['__type'] = $messageType;
				$message['__parent'] = $messageId;
			}
		}

		if (!empty($_REQUEST['email']))
		{
			$message['FIELD_RCPT'] = $_REQUEST['email'];
		}

		Mail\Helper\Message::prepare($message);

		$this->arResult['MESSAGE'] = $message;
		$this->arResult['EMAILS'] = [];//Mail\Helper\Recipient::loadMailContacts();
		$this->arResult['ANALYTICS'] = $this->arParams['ANALYTICS'];

		if ($this->getTemplateName() === self::COMPOSE_TEMPLATE_NAME)
		{
			$draftsAvailable = Mail\Helper\Config\Feature::isInternalDraftsWebAvailable()
				&& !is_array($scheduledMessage);
			$draftClientId = $draftsAvailable ? Main\UuidGenerator::generateV4() : '';
			$this->arResult['COMPOSE_FORM_DATA'] = (new Mail\Service\Compose\ComposeFormDataProvider())
				->getInitialData($message, [
					'replyAll' => $isReplyAll,
					'analyticsSource' => $this->arParams['ANALYTICS']['SOURCE'] ?? '',
					'analyticsElement' => $this->getRequestedAnalyticsElement(),
					'pathToMessageList' => $this->arParams['~PATH_TO_MAIL_MSG_LIST'] ?? '',
					'pathToHome' => $this->arParams['~PATH_TO_MAIL_HOME'] ?? '',
					'draftId' => $draftsAvailable ? $draftId : 0,
					'draftClientId' => $draftClientId,
					'scheduledMessage' => $scheduledMessage,
				])
			;
		}
		else
		{
			$this->fillLegacyFormResult($message, $draftId);
		}

		$this->includeComponentTemplate();
	}

	/**
	 * Keys of the old form only. The redesigned template takes everything from COMPOSE_FORM_DATA, where
	 * the service builds the very same values, so none of this is computed for it.
	 */
	private function fillLegacyFormResult(array $message, int $draftId): void
	{
		$this->isCrmEnable = MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm();
		$this->arResult['CRM_ENABLE'] = ($this->isCrmEnable ? 'Y' : 'N');
		$this->arResult['LAST_RCPT'] = Mail\Helper\Recipient::loadLastRcpt();
		$this->arResult['COPILOT_PARAMS'] = self::prepareCopilotParams();
		$this->arResult['DRAFT_AVAILABLE'] = Mail\Helper\Config\Feature::isInternalDraftsWebAvailable();
		$this->arResult['DRAFT_ID'] = $this->arResult['DRAFT_AVAILABLE'] ? $draftId : 0;
		$this->arResult['DRAFT_CLIENT_ID'] = Main\UuidGenerator::generateV4();
		$this->arResult['DRAFT_MODE'] = $message['__type'] ?? 'new';
		$this->arResult['DRAFT_PARENT_MESSAGE_ID'] = (int)($message['__parent'] ?? 0) ?: null;
		$this->arResult['LARGE_ATTACHMENT_LOCAL_FEATURE_AVAILABLE']
			= Mail\Helper\Config\Feature::isLargeAttachmentDiskUploadAvailable();
		$this->arResult['LARGE_ATTACHMENT_FEATURE_AVAILABLE']
			= Mail\Helper\LicenseManager::isLargeAttachmentAutoUploadEnabled();
		$this->arResult['LARGE_ATTACHMENT_SHOW_AHA'] = $this->arResult['LARGE_ATTACHMENT_FEATURE_AVAILABLE']
			&& !Mail\Helper\Config\Guide::wasLargeAttachmentAhaShown();
		$this->arResult['LARGE_ATTACHMENT_AHA_OPTION_NAME'] = Mail\Helper\Config\Guide::getLargeAttachmentAhaGuideOptionName();
		$this->arResult['LARGE_ATTACHMENT_POST_SEND_SUPPRESSED'] = Mail\Helper\Config\Guide::wasLargeAttachmentPostSendPromptSuppressed();
		$this->arResult['LARGE_ATTACHMENT_POST_SEND_OPTION_NAME'] = Mail\Helper\Config\Guide::getLargeAttachmentPostSendPromptOptionName();
	}

	/**
	 * The form saves a scheduled letter only while the service still lets it be changed. A letter of any other
	 * status is refused here, at the entrance, and the refusal names the status: otherwise the author fills the
	 * form in and learns of it from the save.
	 */
	private static function getScheduledStatusRefusal(string $status): ?string
	{
		if (in_array($status, self::EDITABLE_SCHEDULED_STATUSES, true))
		{
			return null;
		}

		$phrase = self::SCHEDULED_STATUS_REFUSAL_PHRASES[$status] ?? 'MAIL_CLIENT_ELEMENT_DENIED';

		return (string)Loc::getMessage($phrase);
	}

	/**
	 * Analytics element the entry point of the form has put into the address. Which values are allowed
	 * is decided by the service that owns the initial data.
	 */
	private function getRequestedAnalyticsElement(): string
	{
		$element = $_REQUEST['c_element'] ?? null;

		return is_string($element) ? $element : '';
	}

	/**
	 * The message a reply or a forward is built from.
	 *
	 * @return array Empty when the message is out of reach.
	 *
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	private function getQuotedMessage(int $messageId): array
	{
		$message = Mail\MailMessageTable::getList([
			'runtime' => [
				new Main\ORM\Fields\Relations\Reference(
					'MESSAGE_UID',
					'Bitrix\Mail\MailMessageUidTable',
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					[
						'join_type' => 'INNER',
					],
				),
			],
			'select' => [
				'*',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'MAILBOX_NAME' => 'MAILBOX.NAME',
				'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				'DIR_MD5' => 'MESSAGE_UID.DIR_MD5',
				'MSG_UID' => 'MESSAGE_UID.MSG_UID',
				'INTERNALDATE' => 'MESSAGE_UID.INTERNALDATE',
				// The generation of the placement: what tells a lazy download it is still usable
				'GENERATION_ID' => 'MESSAGE_UID.GENERATION_ID',
				// The version of the folder the uid was written under: the same download reads it too
				'DIR_UIDV' => 'MESSAGE_UID.DIR_UIDV',
			],
			'filter' => array_merge(
				['=ID' => $messageId],
				// Of the placements of the message, the download barrier only serves the active one
				Mail\Helper\Message\Loader\QueryBuilder::generationScopeFilterOfMessages(
					[$messageId],
					'MESSAGE_UID.',
				),
			),
		])->fetch();

		return is_array($message) ? $message : [];
	}

	private static function prepareCopilotParams(): array
	{
		return AI\Settings::instance()->getMailCopilotParams(AI\Settings::MAIL_NEW_MESSAGE_CONTEXT_ID);
	}
}
