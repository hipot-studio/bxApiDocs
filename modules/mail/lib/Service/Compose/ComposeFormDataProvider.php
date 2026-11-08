<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Compose;

use Bitrix\Calendar;
use Bitrix\Mail\Helper;
use Bitrix\Mail\Integration\AI;
use Bitrix\Mail\Integration\Crm;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarService;
use Bitrix\Mail\Message;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;

/**
 * Initial data of the redesigned compose form (DTO-01).
 *
 * The service owns the whole structure: the template only serialises what is returned here, so no
 * key reaches the form past this class. Every source is taken ready-made: nothing that already
 * exists is computed a second time.
 *
 * Input arrives as arguments only: the message the component has already prepared
 * (Helper\Message::prepare()) and the request parameters it has already read. The service touches
 * neither $_REQUEST nor arResult.
 *
 * A missing mailbox is not an error: the sender is simply not preselected. A neighbouring module
 * that is not there (calendar, ai, disk, bitrix24) turns its section off instead of breaking the
 * build.
 *
 * Reply and forward fill the very same keys and add none of their own.
 */
class ComposeFormDataProvider
{
	/** Scenarios of DTO-01: the message type read by the component plus the "reply all" flag. */
	public const SCENARIO_NEW = 'new';

	public const SCENARIO_REPLY = 'reply';

	public const SCENARIO_REPLY_ALL = 'replyAll';

	public const SCENARIO_FORWARD = 'forward';

	public const SEND_ACTION_URL = '/bitrix/services/main/ajax.php?c=bitrix%3Amail.client&action=sendMessage&mode=ajax';

	public const SIGNATURES_SETTINGS_PATH = '/mail/signatures';

	/**
	 * Templates are stored and edited in CRM: the address is the section its own settings lead to
	 * (crm.configs, "Почтовые шаблоны").
	 */
	public const TEMPLATES_MANAGE_PATH = '/crm/configs/mailtemplate/';

	/**
	 * Entry points the form is opened from, as the analytics event names them. The value comes from
	 * the entry point itself, so it is checked against this list before it reaches the form.
	 */
	private const ANALYTICS_ELEMENTS = ['compose_button', 'reply', 'reply_all', 'fast_reply', 'forward'];

	private const CALENDAR_SHARING_TOUR_OPTION_CATEGORY = 'ui-tour';

	/** Tour key of the old form: whoever has seen the hint there must not see it here again. */
	private const CALENDAR_SHARING_TOUR_OPTION_NAME = 'view_date_mail-start-calendar-sharing-tour';

	private SignatureListProvider $signatureListProvider;

	private ReplyRecipientResolver $replyRecipientResolver;

	private MessageQuoteBuilder $messageQuoteBuilder;

	private Crm\CrmTemplateProvider $crmTemplateProvider;

	private Crm\MailTemplateEditor $mailTemplateEditor;

	private AvatarService $avatarService;

	private SenderListProvider $senderListProvider;

	public function __construct(
		?SignatureListProvider $signatureListProvider = null,
		?ReplyRecipientResolver $replyRecipientResolver = null,
		?MessageQuoteBuilder $messageQuoteBuilder = null,
		?Crm\CrmTemplateProvider $crmTemplateProvider = null,
		?Crm\MailTemplateEditor $mailTemplateEditor = null,
		?AvatarService $avatarService = null,
		?SenderListProvider $senderListProvider = null,
	)
	{
		$this->signatureListProvider = $signatureListProvider ?? new SignatureListProvider();
		$this->replyRecipientResolver = $replyRecipientResolver ?? new ReplyRecipientResolver();
		$this->messageQuoteBuilder = $messageQuoteBuilder ?? new MessageQuoteBuilder();
		$this->crmTemplateProvider = $crmTemplateProvider ?? new Crm\CrmTemplateProvider();
		$this->mailTemplateEditor = $mailTemplateEditor ?? new Crm\MailTemplateEditor();
		$this->avatarService = $avatarService ?? new AvatarService();
		$this->senderListProvider = $senderListProvider ?? new SenderListProvider();
	}

	/**
	 * Both scenarios of an answer behave alike wherever the source message is carried over: the
	 * "reply all" flag only widens the recipients.
	 */
	public static function isReplyScenario(string $scenario): bool
	{
		return $scenario === self::SCENARIO_REPLY || $scenario === self::SCENARIO_REPLY_ALL;
	}

	/**
	 * @param array $message Message prepared by Helper\Message::prepare().
	 * @param array{
	 *     replyAll?: bool,
	 *     analyticsSource?: string,
	 *     analyticsElement?: string,
	 *     pathToMessageList?: string,
	 *     pathToHome?: string,
	 *     draftId?: int,
	 *     draftClientId?: string,
	 *     scheduledMessage?: array<string, mixed>|null,
	 * } $params The "reply all" request flag the component has read, ANALYTICS.SOURCE, the analytics
	 *     element of the entry point and the two path templates of the router.
	 */
	public function getInitialData(array $message, array $params = []): array
	{
		$senders = $this->applyMailboxAvatars($this->loadSenders());
		$senderEmail = (string)($message['__email'] ?? '');
		$mailboxId = (int)($message['MAILBOX_ID'] ?? 0);
		$mailboxId = $mailboxId > 0 ? $mailboxId : null;
		$userId = $this->getCurrentUserId();
		$scenario = $this->resolveScenario($message, $params);
		$parentMessageId = $this->resolveParentMessageId($message);
		$carriedOver = $this->messageQuoteBuilder->build($message, $parentMessageId, $scenario);

		$data = [
			'scenario' => $scenario,
			'title' => $this->resolveTitle($scenario),
			'messageId' => 0,
			'parentMessageId' => $parentMessageId,
			'mailbox' => [
				'id' => $mailboxId,
				'email' => $senderEmail,
			],
			'senders' => $senders,
			'selectedSender' => $this->resolveSelectedSender($senderEmail, $senders),
			'signatures' => $this->buildSignatures($senders),
			'recipients' => $this->buildRecipients($message, $scenario),
			'subject' => (string)($message['SUBJECT'] ?? ''),
			'body' => [
				'quote' => $carriedOver['quote'],
				'quoteFolded' => $parentMessageId !== null && self::isReplyScenario($scenario),
			],
			'attachments' => [
				'files' => $carriedOver['files'],
				'folded' => $parentMessageId !== null,
			],
			'limits' => $this->buildLimits(),
			'calendarSharing' => $this->buildCalendarSharing(),
			'copilot' => $this->loadCopilotParams(),
			'largeAttachment' => $this->buildLargeAttachment(),
			'attachmentReminder' => [
				'enabled' => $this->isAttachmentReminderEnabled(),
			],
			'send' => $this->buildSend($message, $parentMessageId, $scenario, $mailboxId),
			'analytics' => [
				'section' => $this->resolveAnalyticsSection($params),
				'element' => $this->resolveAnalyticsElement($params, $scenario),
			],
			'features' => [
				'unfinishedElements' => Helper\Config\Feature::isComposeUnfinishedElementsAvailable(),
				'scheduledMessages' => Helper\Config\Feature::isScheduledMessageAvailable(),
				'undoSend' => Helper\Config\Feature::isUndoSendAvailable(),
				'templates' => Helper\Config\Feature::isComposeTemplatesAvailable(),
				'saveTemplate' => Helper\Config\Feature::isComposeTemplatesAvailable()
					&& $this->crmTemplateProvider->canCreatePersonal($userId),
				'subjectGeneration' => $this->isSubjectGenerationAvailable(),
			],
			'authorTimezoneId' => $this->resolveAuthorTimezoneId(),
			'draft' => [
				'id' => max(0, (int)($params['draftId'] ?? 0)),
				'revision' => 0,
				'clientId' => (string)($params['draftClientId'] ?? ''),
			],
			'paths' => $this->buildPaths($mailboxId, $params),
		];

		$scheduledMessage = $params['scheduledMessage'] ?? null;

		return is_array($scheduledMessage)
			? $this->applyScheduledMessage($data, $scheduledMessage, $senders)
			: $data;
	}

	/** @param array<string, mixed> $scheduledMessage */
	private function applyScheduledMessage(array $data, array $scheduledMessage, array $senders): array
	{
		$sender = (array)($scheduledMessage['SENDER'] ?? []);
		$senderEmail = (string)($sender['email'] ?? '');
		$recipients = (array)($scheduledMessage['RECIPIENTS'] ?? []);
		$scheduledAt = $scheduledMessage['SCHEDULED_AT'] ?? null;

		$data['mailbox']['id'] = (int)($scheduledMessage['MAILBOX_ID'] ?? 0);
		$data['mailbox']['email'] = $senderEmail;
		$data['selectedSender'] = $this->resolveSelectedSender($senderEmail, $senders);
		$data['recipients'] = [
			'to' => $this->toDialogItems((array)($recipients['to'] ?? []), false),
			'cc' => $this->toDialogItems((array)($recipients['cc'] ?? []), false),
			'bcc' => $this->toDialogItems((array)($recipients['bcc'] ?? []), false),
		];
		$data['subject'] = (string)($scheduledMessage['SUBJECT'] ?? '');
		$data['send']['inReplyTo'] = isset($scheduledMessage['IN_REPLY_TO'])
			? (string)$scheduledMessage['IN_REPLY_TO']
			: null;
		$data['body'] = [
			'content' => (string)($scheduledMessage['BODY'] ?? ''),
			'quote' => '',
			'quoteFolded' => false,
		];
		$attachments = (array)($scheduledMessage['ATTACHMENTS'] ?? []);
		$fileSizes = $this->loadFileSizes(array_map(
			static fn(array $attachment): int => (int)($attachment['FILE_ID'] ?? 0),
			$attachments,
		));
		$data['attachments'] = [
			'files' => array_values(array_filter(array_map(
				static function(array $attachment) use ($fileSizes): ?array {
					$objectId = (int)($attachment['SOURCE_OBJECT_ID'] ?? 0);
					if ($objectId <= 0)
					{
						return null;
					}

					$bytes = (int)($fileSizes[(int)($attachment['FILE_ID'] ?? 0)] ?? 0);

					return [
						'id' => 'n' . $objectId,
						'name' => (string)($attachment['FILE_NAME'] ?? ''),
						'size' => $bytes > 0 ? \CFile::formatSize($bytes) : '',
						'bytes' => $bytes,
					];
				},
				$attachments,
			))),
			'folded' => false,
		];
		$data['scheduledMessage'] = [
			'id' => (int)($scheduledMessage['ID'] ?? 0),
			'revision' => (int)($scheduledMessage['REVISION'] ?? 0),
			'scheduledAt' => $scheduledAt instanceof \Bitrix\Main\Type\DateTime
				? $scheduledAt->format(DATE_ATOM)
				: '',
			'timezoneId' => (string)($scheduledMessage['AUTHOR_TIMEZONE_ID'] ?? ''),
			'attachments' => array_map(static fn(array $attachment): array => [
				'id' => (int)($attachment['ID'] ?? 0),
				'objectId' => ($objectId = (int)($attachment['SOURCE_OBJECT_ID'] ?? 0)) > 0 ? $objectId : null,
				'fileId' => $objectId > 0 ? 'n' . $objectId : null,
			], $attachments),
		];

		return $data;
	}

	/**
	 * Scenario of the form: the type of message the component has read from the request, refined by
	 * the "reply all" flag, which is meaningful for a reply only.
	 */
	private function resolveScenario(array $message, array $params): string
	{
		$messageType = (string)($message['__type'] ?? '');

		if ($messageType === 'forward')
		{
			return self::SCENARIO_FORWARD;
		}

		if ($messageType === 'reply')
		{
			return empty($params['replyAll']) ? self::SCENARIO_REPLY : self::SCENARIO_REPLY_ALL;
		}

		return self::SCENARIO_NEW;
	}

	/**
	 * Title of the panel: an answer is titled alike in both of its scenarios, and a scenario the
	 * service does not know is titled as a letter of its own.
	 */
	private function resolveTitle(string $scenario): string
	{
		$phrase = match ($scenario)
		{
			self::SCENARIO_REPLY, self::SCENARIO_REPLY_ALL => 'MAIL_COMPOSE_FORM_TITLE_REPLY',
			self::SCENARIO_FORWARD => 'MAIL_COMPOSE_FORM_TITLE_FORWARD',
			default => 'MAIL_COMPOSE_FORM_TITLE_NEW',
		};

		return (string)Loc::getMessage($phrase);
	}

	/**
	 * Source message of the form: it is what the quote, the attachments and the link to the answered
	 * letter are carried over from.
	 */
	private function resolveParentMessageId(array $message): ?int
	{
		$parentMessageId = (int)($message['__parent'] ?? 0);

		return $parentMessageId > 0 ? $parentMessageId : null;
	}

	/**
	 * The letter is linked to the one it answers, and a forward is not: it starts a thread of its own.
	 * A source message with no Message-Id leaves the link empty instead of an empty header.
	 */
	private function buildSend(array $message, ?int $parentMessageId, string $scenario, ?int $mailboxId): array
	{
		$answers = $parentMessageId !== null && self::isReplyScenario($scenario);
		$sourceMessageId = (string)($message['MSG_ID'] ?? '');

		return [
			'actionUrl' => self::SEND_ACTION_URL,
			'inReplyTo' => $answers && $sourceMessageId !== '' ? $sourceMessageId : null,
			'mailboxId' => $answers ? $mailboxId : null,
		];
	}

	/**
	 * Both filled fields are converted by one call and split back afterwards: an item of the address
	 * book is fully determined by the address it was built from, so the split gives every field the
	 * items of its own addresses.
	 *
	 * An answer is preselected from the headers of the letter alone, the way the embedded reply form
	 * of the message view screen does it. The address of the '?email=' entry keeps going through the
	 * address book, as the compose screen has always sent it there.
	 */
	private function buildRecipients(array $message, string $scenario): array
	{
		$recipients = $this->replyRecipientResolver->resolve($message, $scenario);
		$updateFromAddressBook = !self::isReplyScenario($scenario);
		$items = $this->indexItemsByAddress(
			$this->toDialogItems(
				array_merge($recipients['to'], $recipients['cc']),
				$updateFromAddressBook,
			),
		);

		return [
			'to' => $this->takeItemsOf($recipients['to'], $items),
			'cc' => $this->takeItemsOf($recipients['cc'], $items),
			'bcc' => $this->toDialogItems($recipients['bcc'], $updateFromAddressBook),
		];
	}

	private function toDialogItems(array $recipients, bool $updateFromAddressBook): array
	{
		return empty($recipients)
			? []
			: $this->loadPreselectedRecipients($recipients, $updateFromAddressBook);
	}

	/**
	 * The selector keeps one item per address, so the address is the key back to it.
	 */
	private function indexItemsByAddress(array $items): array
	{
		$indexed = [];

		foreach ($items as $item)
		{
			$indexed[(string)($item['id'] ?? '')] = $item;
		}

		return $indexed;
	}

	/**
	 * Items of one field, in the order its addresses came in. An address named twice in the same
	 * field is preselected once, exactly as the selector itself does it.
	 */
	private function takeItemsOf(array $recipients, array $items): array
	{
		$taken = [];

		foreach ($recipients as $recipient)
		{
			$email = (string)($recipient['email'] ?? '');

			if (isset($items[$email]) && !isset($taken[$email]))
			{
				$taken[$email] = $items[$email];
			}
		}

		return array_values($taken);
	}

	/**
	 * Sender the form starts on, as a 'formated' value of the sender list.
	 *
	 * The address of the message wins; the list keeps the order it came in, so the first element is
	 * the fallback. With nothing available to send from, nothing is preselected.
	 */
	private function resolveSelectedSender(string $senderEmail, array $senders): ?string
	{
		if (empty($senders))
		{
			return null;
		}

		$senderEmail = mb_strtolower(trim($senderEmail));
		if ($senderEmail !== '')
		{
			foreach ($senders as $sender)
			{
				if (mb_strtolower((string)($sender['email'] ?? '')) === $senderEmail)
				{
					return $this->toSelectedSender($sender);
				}
			}
		}

		return $this->toSelectedSender($senders[0]);
	}

	private function applyMailboxAvatars(array $senders): array
	{
		$mailboxIds = [];
		foreach ($senders as $sender)
		{
			$mailboxId = (int)($sender['mailboxId'] ?? 0);
			if ($mailboxId > 0)
			{
				$mailboxIds[] = $mailboxId;
			}
		}

		$mailboxIds = array_values(array_unique($mailboxIds));
		if ($mailboxIds === [])
		{
			return $senders;
		}

		$avatars = $this->loadMailboxAvatars($mailboxIds);
		foreach ($senders as $index => $sender)
		{
			$mailboxId = (int)($sender['mailboxId'] ?? 0);
			$avatarUrl = $avatars[$mailboxId]['url'] ?? null;
			if ($mailboxId > 0 && $avatarUrl !== null)
			{
				$senders[$index]['avatar'] = $avatarUrl;
			}
		}

		return $senders;
	}

	private function toSelectedSender(array $sender): ?string
	{
		$formated = (string)($sender['formated'] ?? '');

		return $formated === '' ? null : $formated;
	}

	private function resolveAnalyticsSection(array $params): string
	{
		$section = (string)($params['analyticsSource'] ?? '');

		return $section === '' ? 'mail' : $section;
	}

	/**
	 * Entry point the form was opened from. The fast reply panel of the message view screen answers
	 * the same way its reply button does, so only the value the entry point puts into the address
	 * tells the two apart. An entry point that names none is recognised by the scenario, as before.
	 */
	private function resolveAnalyticsElement(array $params, string $scenario): string
	{
		$element = (string)($params['analyticsElement'] ?? '');

		if (in_array($element, self::ANALYTICS_ELEMENTS, true))
		{
			return $element;
		}

		return match ($scenario)
		{
			self::SCENARIO_REPLY => 'reply',
			self::SCENARIO_REPLY_ALL => 'reply_all',
			self::SCENARIO_FORWARD => 'forward',
			default => 'compose_button',
		};
	}

	private function buildSignatures(array $senders): array
	{
		$signatures = $this->signatureListProvider->getSignatures($this->getCurrentUserId(), $senders);
		$signatures['settingsPath'] = self::SIGNATURES_SETTINGS_PATH;

		return $signatures;
	}

	private function buildPaths(?int $mailboxId, array $params): array
	{
		$paths = [
			'messageList' => $this->resolveMessageListPath($mailboxId, $params),
			'home' => (string)($params['pathToHome'] ?? ''),
		];

		if ($this->mailTemplateEditor->isAvailable($this->getCurrentUserId()))
		{
			$siteId = $this->getSiteId();
			$paths['templatesManage'] = Crm\MailTemplateEditor::getListUrl($siteId);
			$paths['templatesCreate'] = Crm\MailTemplateEditor::getAddUrl($siteId);
		}
		else
		{
			$paths['templatesManage'] = $this->resolveTemplatesManagePath();
		}

		return $paths;
	}

	protected function getSiteId(): string
	{
		return defined('SITE_ID') ? (string)SITE_ID : '';
	}

	/**
	 * Address of the section the templates are managed in, empty when the user must not be led there.
	 * The section belongs to CRM and is open on the same terms the templates themselves are, so the
	 * source of the templates is asked rather than a second rule written here. A site of its own lives
	 * in a directory of its own, and the section is published relative to it.
	 */
	protected function resolveTemplatesManagePath(): string
	{
		if (!$this->crmTemplateProvider->isAvailable($this->getCurrentUserId()))
		{
			return '';
		}

		return rtrim($this->getSiteDir(), '/') . self::TEMPLATES_MANAGE_PATH;
	}

	protected function getSiteDir(): string
	{
		return defined('SITE_DIR') ? (string)SITE_DIR : '';
	}

	/**
	 * List address of the mailbox the letter is sent from. With no mailbox there is nothing to put
	 * into the template, and a path with an empty segment leads nowhere; a screen that passed no
	 * template at all substitutes into an empty address just the same. The form closes to 'home'
	 * instead, so the address is left out altogether: an empty string is not one of the two states
	 * the contract knows.
	 */
	private function resolveMessageListPath(?int $mailboxId, array $params): ?string
	{
		if ($mailboxId === null)
		{
			return null;
		}

		$path = \CComponentEngine::makePathFromTemplate(
			(string)($params['pathToMessageList'] ?? ''),
			['id' => $mailboxId],
		);

		return $path === '' ? null : $path;
	}

	protected function buildLimits(): array
	{
		return [
			'recipientsPerField' => Helper\LicenseManager::getEmailsLimitToSendMessage(),
			'recipientsTotal' => Helper\LicenseManager::getMessageRecipientsTotalLimit(),
			'maxAttachmentsSize' => (int)Helper\Message::getMaxAttachedFilesSize(),
			'maxAttachmentsSizeAfterEncoding' => (int)Helper\Message::getMaxAttachedFilesSizeAfterEncoding(),
		];
	}

	protected function buildCalendarSharing(): array
	{
		if (!$this->isCalendarModuleAvailable())
		{
			return [
				'available' => false,
				'featureEnabled' => false,
				'crmFeatureEnabled' => false,
				'showTour' => false,
				'userCalendarPath' => '',
			];
		}

		return [
			'available' => true,
			'featureEnabled' => Calendar\Integration\Bitrix24Manager::isFeatureEnabled('calendar_sharing'),
			'crmFeatureEnabled' => Calendar\Integration\Bitrix24Manager::isFeatureEnabled('crm_event_sharing'),
			'showTour' => \CUserOptions::GetOption(
				self::CALENDAR_SHARING_TOUR_OPTION_CATEGORY,
				self::CALENDAR_SHARING_TOUR_OPTION_NAME,
				null,
			) === null,
			// Depends on the site setting and on the extranet, so only the server knows it. An empty
			// value leaves the popup of the disabled sharing without the link to the calendar.
			'userCalendarPath' => (string)\CCalendar::GetPathForCalendarEx($this->getCurrentUserId()),
		];
	}

	protected function buildLargeAttachment(): array
	{
		$featureAvailable = Helper\LicenseManager::isLargeAttachmentAutoUploadEnabled();

		return [
			'localFeatureAvailable' => Helper\Config\Feature::isLargeAttachmentDiskUploadAvailable(),
			'featureAvailable' => $featureAvailable,
			'showAha' => $featureAvailable && !Helper\Config\Guide::wasLargeAttachmentAhaShown(),
			'ahaOptionName' => Helper\Config\Guide::getLargeAttachmentAhaGuideOptionName(),
			'postSendPromptSuppressed' => Helper\Config\Guide::wasLargeAttachmentPostSendPromptSuppressed(),
			'postSendPromptOptionName' => Helper\Config\Guide::getLargeAttachmentPostSendPromptOptionName(),
			'folderName' => '',
		];
	}

	protected function isAttachmentReminderEnabled(): bool
	{
		return Option::get('main', 'mail_form_attachment_reminder', 'Y') === 'Y';
	}

	protected function isCalendarModuleAvailable(): bool
	{
		return Loader::includeModule('calendar');
	}

	/**
	 * CoPilot parameters are passed on as they come. The context is the same in every scenario of
	 * the form, exactly as in the old one.
	 */
	protected function loadCopilotParams(): array
	{
		return AI\Settings::instance()->getMailCopilotParams(AI\Settings::MAIL_NEW_MESSAGE_CONTEXT_ID);
	}

	/**
	 * Whether the subject generation button belongs in the subject line (DTO-01). Only the server
	 * decides it; the form does not recompute it.
	 *
	 * The regular, non-CRM mode is the only one asked about: the new form is opened from the mail
	 * route alone, and the CRM composer stays on the old one. A missing ai module or a switched off
	 * portal setting answer false instead of breaking the build of the form.
	 */
	protected function isSubjectGenerationAvailable(): bool
	{
		return (new AI\SubjectAvailability())->isAvailable();
	}

	protected function loadSenders(): array
	{
		return $this->senderListProvider->getSenders();
	}

	protected function loadMailboxAvatars(array $mailboxIds): array
	{
		return $this->avatarService->getDisplayData($mailboxIds);
	}

	/**
	 * Preselected recipients in the shape of the entity selector the form mounts.
	 *
	 * Updating from the address book replaces the name of an item with the mail contact of the current
	 * user and leaves out an address that has none. An answer cannot afford that: a correspondent
	 * reaches the address book asynchronously, and in a shared mailbox the contacts belong to the owner
	 * of the mailbox rather than to whoever answers.
	 */
	protected function loadPreselectedRecipients(array $recipients, bool $updateFromAddressBook): array
	{
		return Message::getSelectedRecipientsForDialog($recipients, $updateFromAddressBook)->toArray();
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * Sizes of the files of the portal storage, by their identifiers, read in one query however many there are.
	 *
	 * The attachments of a scheduled letter carry no size of their own: the letter owns a copy of every file
	 * (ScheduledMessageAttachmentService::copyForUser()), and the storage is where its size is kept. The copy is
	 * asked for rather than the Disk object it was made from: the object can be gone by the time the letter is
	 * changed, while the copy lives as long as the letter does.
	 *
	 * @param int[] $fileIds
	 * @return array<int, int> File id => size in bytes. A file that is gone is left out.
	 */
	protected function loadFileSizes(array $fileIds): array
	{
		$fileIds = array_values(array_unique(array_filter($fileIds, static fn(int $fileId): bool => $fileId > 0)));

		if ($fileIds === [])
		{
			return [];
		}

		$sizes = [];
		$rows = FileTable::getList([
			'select' => ['ID', 'FILE_SIZE'],
			'filter' => ['@ID' => $fileIds],
		]);

		foreach ($rows as $row)
		{
			$sizes[(int)$row['ID']] = (int)$row['FILE_SIZE'];
		}

		return $sizes;
	}

	/**
	 * Timezone a letter is scheduled in, as an IANA identifier: the moment of the send is counted in the
	 * same zone every other date of the mail is drawn in. The zone of the profile is that source, and
	 * \CTimeZone::getOffset() reads the very same field.
	 *
	 * A profile left on the automatic zone keeps none, and there is nothing to send: the form falls back
	 * to the zone of the browser then, which is where the automatic mode takes it from as well.
	 */
	protected function resolveAuthorTimezoneId(): string
	{
		global $USER;

		return is_object($USER) ? trim((string)$USER->GetParam('TIME_ZONE')) : '';
	}
}
