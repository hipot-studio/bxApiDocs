<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Feature\Node\EmailNodeSenderTemplatesFlag;
use Bitrix\Bizproc\Public\Integration\Sender\Service\EmailTemplateService;
use Bitrix\Crm;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Main;
use Bitrix\Main\Config;
use Bitrix\Main\Mail;
use Bitrix\Crm\Automation\ClientCommunications\ClientCommunications;
use Bitrix\Mail\Helper;

// Without the sender field able to load the "add sender" form itself the node keeps the legacy dialog that loads it.
if (method_exists(\Bitrix\Bizproc\Controller\FieldType::class, 'getMailConfirmFormAction'))
{
	abstract class CBPCrmSendEmailActivityBase extends CBPActivity implements IBPConfigurableActivity
	{
	}
}
else
{
	abstract class CBPCrmSendEmailActivityBase extends CBPActivity
	{
	}
}

class CBPCrmSendEmailActivity extends CBPCrmSendEmailActivityBase
{
	public const TEXT_TYPE_BBCODE = 'bbcode';
	public const TEXT_TYPE_HTML = 'html';
	public const ATTACHMENT_TYPE_FILE = 'file';
	public const ATTACHMENT_TYPE_DISK = 'disk';

	private const SELECT_RULE_FIRST = 'first';
	private const SELECT_RULE_LAST = 'last';
	//private const SELECT_RULE_ALL = 'all';

	private const NODE_FORM_FIELD_ORDER = [
		'MessageFrom',
		'Subject',
		'MessageText',
		'MessageTextType',
		'EmailType',
		'EmailSelectRule',
		'AttachmentType',
		'Attachment',
		'AttachmentDisk',
		'UseLinkTracker',
	];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'Subject' => '',
			'From' => null, //deprecated, WAF unstable property
			'MessageFrom' => null,
			'MessageText' => '',
			'MessageTextType' => '',
			'MessageTextEncoded' => 0,
			'EmailType' => null,
			'EmailSelectRule' => null,
			'UseLinkTracker' => 'Y',
			'AttachmentType' => static::ATTACHMENT_TYPE_FILE,
			'Attachment' => [],
			'EmailTemplateId' => null,
		];
	}

	public function Execute()
	{
		if (!CModule::IncludeModule("crm") || !CModule::IncludeModule('subscribe'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$emailTemplateId = $this->getUsableEmailTemplateId();
		if ($emailTemplateId === null && $this->MessageText === '')
		{
			return CBPActivityExecutionStatus::Closed;
		}

		[$typeName, $ownerId] = mb_split('_(?=[^_]*$)', $this->GetDocumentId()[2]);
		$ownerTypeId = \CCrmOwnerType::ResolveID($typeName);
		$ownerId = (int)$ownerId;

		$userId = CCrmBizProcHelper::getDocumentResponsibleId($this->GetDocumentId());
		if ($userId <= 0)
		{
			$this->writeError(GetMessage('CRM_SEMA_NO_RESPONSIBLE'), $userId);

			return CBPActivityExecutionStatus::Closed;
		}

		$rawFromProperty = $this->getRawProperty('MessageFrom');
		$fromProperty = $this->MessageFrom;
		if (!$fromProperty) //compatibility
		{
			$fromProperty = $this->From;
			if (!$rawFromProperty)
			{
				$rawFromProperty = $this->getRawProperty('From');
			}
		}

		$fromInfo = $this->getFromEmail($userId, $fromProperty);

		if (!$fromInfo)
		{
			$this->logDebug();
			$this->writeError(GetMessage('CRM_SEMA_NO_FROM'), $userId);

			return CBPActivityExecutionStatus::Closed;
		}

		if (!$this->isSavedSenderAvailable($userId, $rawFromProperty, $fromInfo))
		{
			$this->writeError(GetMessage('CRM_SEMA_SENDER_UNAVAILABLE_FOR_RESPONSIBLE'), $userId);

			return CBPActivityExecutionStatus::Closed;
		}

		$from = $fromInfo['from'];
		$userImap = $fromInfo['userImap'];
		$injectUrn = $fromInfo['injectUrn'];
		$reply = $fromInfo['reply'];
		$fromEmail = $fromInfo['fromEmail'];
		$fromEncoded = $fromInfo['fromEncoded'];

		[$to, $comEntityTypeId, $comEntityId] = $this->getToEmail($ownerTypeId, $ownerId);

		$this->logDebug([
			'MessageFrom' => $from,
			'MessageTo' => $to,
		]);

		if (empty($to))
		{
			$this->writeError(GetMessage('CRM_SEMA_NO_ADDRESSER'), $userId);

			return CBPActivityExecutionStatus::Closed;
		}

		// Bindings & Communications -->
		$bindings = [
			[
				'OWNER_TYPE_ID' => $ownerTypeId,
				'OWNER_ID' => $ownerId
			]
		];
		if (!($comEntityTypeId === $ownerTypeId && $comEntityId === $ownerId))
		{
			$bindings[] = [
				'OWNER_TYPE_ID' => $comEntityTypeId,
				'OWNER_ID' => $comEntityId
			];
		}

		$communications = [
			[
				'TYPE' => 'EMAIL',
				'VALUE' => $to,
				'ENTITY_TYPE_ID' => $comEntityTypeId,
				'ENTITY_ID' => $comEntityId,
			]
		];
		// <-- Bindings & Communications

		$subject = $this->getSubject();
		$messageType = $this->MessageTextType;
		$message = $this->getMessageText($messageType);
		$descriptionType = $messageType === self::TEXT_TYPE_HTML ? CCrmContentType::Html : CCrmContentType::BBCode;

		$templateLetter = null;
		if ($emailTemplateId !== null)
		{
			$signatureHtml = '';
			Crm\Integration\Bitrix24Email::addSignature($signatureHtml, CCrmContentType::Html);
			$templateLetter = self::getEmailTemplateService()?->buildLetter(
				$emailTemplateId,
				self::convertMessageToHtml((string)$message, $messageType),
				$signatureHtml,
			);
		}

		if ($templateLetter !== null)
		{
			$messageHtml = $templateLetter;
			$message = $messageHtml;
			$descriptionType = CCrmContentType::Html;
		}
		elseif ($message === '')
		{
			$messageHtml = '';
		}
		else
		{
			CCrmActivity::AddEmailSignature($message, $descriptionType);
			$messageHtml = self::convertMessageToHtml($message, $messageType);

			if (mb_strpos($messageHtml, '</html>') === false)
			{
				$messageHtml = '<html><body>' . $messageHtml . '</body></html>';
			}
		}

		$now = ConvertTimeStamp(time() + CTimeZone::GetOffset(), 'FULL');
		if ($subject === '')
		{
			$subject = GetMessage(
				'CRM_SEMA_DEFAULT_SUBJECT',
				['#DATE#' => $now]
			);
		}

		$description = $message;

		if ($descriptionType === CCrmContentType::Html)
		{
			//$description = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $description);
			$description = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $description);
			$description = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $description);

			$sanitizer = new CBXSanitizer();
			$sanitizer->setLevel(CBXSanitizer::SECURE_LEVEL_LOW);
			$sanitizer->applyDoubleEncode(false);
			$sanitizer->addTags(['style' => []]);
			$description = $sanitizer->SanitizeHtml($description);
		}

		$activityFields = [
			'AUTHOR_ID' => $userId,
			'OWNER_ID' => $ownerId,
			'OWNER_TYPE_ID' => $ownerTypeId,
			'TYPE_ID' => CCrmActivityType::Email,
			'SUBJECT' => $subject,
			'START_TIME' => $now,
			'END_TIME' => $now,
			'COMPLETED' => 'Y',
			'RESPONSIBLE_ID' => $userId,
			'PRIORITY' => CCrmActivityPriority::Medium,
			'DESCRIPTION' => $description,
			'DESCRIPTION_TYPE' => $descriptionType,
			'DIRECTION' => CCrmActivityDirection::Outgoing,
			'BINDINGS' => array_values($bindings),
			'COMMUNICATIONS' => $communications,
		];

		if ($this->AttachmentType === static::ATTACHMENT_TYPE_DISK)
		{
			$attachmentStorageType = Bitrix\Crm\Integration\StorageType::Disk;
			$attachment = $this->filterDownloadableDiskFiles((array)$this->Attachment, $userId);
		}
		else
		{
			$attachmentStorageType = Bitrix\Crm\Integration\StorageType::File;
			$attachment = [];
			$attachmentFiles = (array)$this->ParseValue($this->getRawProperty('Attachment'), 'file');
			$attachmentFiles = CBPHelper::MakeArrayFlat($attachmentFiles);
			$attachmentFiles = array_filter($attachmentFiles);

			if ($attachmentFiles)
			{
				foreach ($attachmentFiles as $fileId)
				{
					$arRawFile = static::makeAttachmentFileArray($fileId);

					if (is_array($arRawFile))
					{
						$fileId = (int)CFile::SaveFile($arRawFile, 'crm');
						if ($fileId > 0)
						{
							$attachment[] = $fileId;
						}
					}
				}
			}
		}

		if ($attachment)
		{
			$activityFields['STORAGE_TYPE_ID'] = $attachmentStorageType;
			$activityFields['STORAGE_ELEMENT_IDS'] = $attachment;
		}

		$addOptions = [
			'REGISTER_SONET_EVENT' => true,
		];

		$arRawFiles =
			isset($activityFields['STORAGE_ELEMENT_IDS']) && !empty($activityFields['STORAGE_ELEMENT_IDS'])
				? \Bitrix\Crm\Integration\StorageManager::makeFileArray(
				$activityFields['STORAGE_ELEMENT_IDS'],
				$activityFields['STORAGE_TYPE_ID']
			)
				: []
		;
		$totalSize = 0;

		foreach ($arRawFiles as $arRawFile)
		{
			$totalSize += $arRawFile['size'];
		}

		$maxSize = Helper\Message::getMaxAttachedFilesSize();
		if ($maxSize > 0 && $maxSize <= ceil($totalSize / 3) * 4) // base64 coef.
		{
			$this->writeError(GetMessage('CRM_SEMA_ACTIVITY_EMAIL_MAX_SIZE_EXCEED',
										 ['#SIZE#' => \CFile::formatSize(Helper\Message::getMaxAttachedFilesSizeAfterEncoding())]
							  ), $userId);

			return CBPActivityExecutionStatus::Closed;
		}

		$emailMeta = [
			'__email' => $fromEmail,
			'from' => $from,
			'replyTo' => $reply,
			'to' => $to,
		];
		// the EmailSent trigger fires inside the add, so the addresses must already be in these fields
		$activityFields['SETTINGS'] = ['EMAIL_META' => $emailMeta];

		$id = CCrmActivity::Add($activityFields, false, false, $addOptions);
		if (!$id)
		{
			$this->writeError(CCrmActivity::GetLastErrorMessage(), $userId);

			return CBPActivityExecutionStatus::Closed;
		}

		$urn = CCrmActivity::PrepareUrn($activityFields);
		$messageId = sprintf(
			'<crm.activity.%s@%s>', $urn,
			defined('BX24_HOST_NAME') ? BX24_HOST_NAME : (
			defined('SITE_SERVER_NAME') && SITE_SERVER_NAME
				? SITE_SERVER_NAME : \COption::getOptionString('main', 'server_name', '')
			)
		);

		\CCrmActivity::update(
			$id,
			[
				//'DESCRIPTION' => $arFields['DESCRIPTION'],
				'URN' => $urn,
				'SETTINGS' => [
					'IS_BATCH_EMAIL' => Config\Option::get('main', 'track_outgoing_emails_read', 'Y') == 'Y' ? false : null,
					'MESSAGE_HEADERS' => [
						'Message-Id' => $messageId,
						'Reply-To' => $reply ?: $from,
					],
					'EMAIL_META' => $emailMeta,
					'BP_ACTIVITY_ID' => $this->GetName(),
					'BP_TEMPLATE_ID' => $this->GetWorkflowTemplateId()
				],
			],
			false,
			false,
			['REGISTER_SONET_EVENT' => true]
		);

		// sending email
		$rcpt = [
			Mail\Mail::encodeHeaderFrom($to, SITE_CHARSET)
		];

		$outgoingSubject = $subject;
		$outgoingBody = $messageHtml ?: getMessage('CRM_SEMA_DEFAULT_BODY');

		if (!empty($injectUrn))
		{
			switch (\CCrmEMailCodeAllocation::getCurrent())
			{
				case \CCrmEMailCodeAllocation::Subject:
					$outgoingSubject = \CCrmActivity::injectUrnInSubject($urn, $outgoingSubject);
					break;
				case \CCrmEMailCodeAllocation::Body:
					$outgoingBody = \CCrmActivity::injectUrnInBody($urn, $outgoingBody, 'html');
					break;
			}
		}

		$attachments = [];
		foreach ($arRawFiles as $key => $item)
		{
			$attachments[] = [
				'ID' => $item['external_id'],
				'NAME' => $item['ORIGINAL_NAME'] ?: $item['name'],
				'PATH' => $item['tmp_name'],
				'CONTENT_TYPE' => $item['type'],
			];
		}

		$context = new Mail\Context();
		$context->setCategory(Mail\Context::CAT_EXTERNAL)
			->setPriority(Mail\Context::PRIORITY_LOW)
			->setCallback(
				(new \Bitrix\Main\Mail\Callback\Config())
					->setModuleId("crm")
					->setEntityType("rpa")
					->setEntityId($urn)
			);
		Crm\Integration\Mail\MessageSender::applySenderIdentity($context, (int)($userImap['ID'] ?? 0));

		$outgoingParams = [
			'CHARSET' => SITE_CHARSET,
			'CONTENT_TYPE' => 'html',
			'ATTACHMENT' => $attachments,
			'TO' => join(', ', $rcpt),
			'SUBJECT' => $outgoingSubject,
			'BODY' => $outgoingBody,
			'HEADER' => [
				'From' => $fromEncoded ?: $fromEmail,
				'Reply-To' => $reply ?: $fromEmail,
				'Message-Id' => $messageId,
			],
			'TRACK_READ' => [
				'MODULE_ID' => 'crm',
				'FIELDS' => ['urn' => $urn],
				'URL_PAGE' => '/pub/mail/read.php',
			],
			'TRACK_CLICK' => ($this->UseLinkTracker === 'Y') ? [
				'MODULE_ID' => 'crm',
				'FIELDS' => ['urn' => $urn],
				'URL_PAGE' => '/pub/mail/click.php',
				'URL_PARAMS' => [],
			] : null,
			'CONTEXT' => $context,
		];

		if ($this->UseLinkTracker === 'Y' && Crm\WebForm\Manager::isEmbeddingAvailable())
		{
			$outgoingParams['TRACK_CLICK']['URL_PARAMS'][Crm\WebForm\Embed\Sign::uriParameterName] = (new Crm\WebForm\Embed\Sign())
				->addEntity($ownerTypeId, $ownerId)
				->pack();
		}

		$transportResult = null;
		if (method_exists(Mail\Mail::class, 'sendResult'))
		{
			$transportResult = Mail\Mail::sendResult($outgoingParams);
			$sendResult = $transportResult->isSuccess();
		}
		else
		{
			$sendResult = Mail\Mail::send($outgoingParams);
		}

		if (!$sendResult)
		{
			$controlledTransportError = Crm\Integration\Mail\MessageSender::getControlledTransportError(
				$transportResult,
			);
			$this->writeError(
				$controlledTransportError?->getMessage() ?? GetMessage('CRM_SEMA_EMAIL_CREATION_CANCELED'),
				$userId,
			);
			\CCrmActivity::delete($id);

			return CBPActivityExecutionStatus::Closed;
		}

		$this->logDebugActivity($id);

		addEventToStatFile(
			'crm',
			'send_email_message',
			sprintf('bp_%s_%s', $this->getWorkflowTemplateId(), $this->getName()),
			trim(trim($messageId), '<>')
		);

		if (!empty($userImap))
		{
			class_exists('Bitrix\Mail\Helper');

			$outgoingHeader = array_merge(
				$outgoingParams['HEADER'],
				[
					'To' => $outgoingParams['TO'],
					'Subject' => $outgoingParams['SUBJECT'],
				]
			);

			$outgoing = new \Bitrix\Mail\DummyMail(array_merge(
				$outgoingParams,
				[
					'HEADER' => $outgoingHeader,
				]
			));

			\Bitrix\Mail\Helper::addImapMessage($userImap, (string)$outgoing, $err);
		}

		// Try add event to entity
		$CCrmEvent = new CCrmEvent();

		$eventText = GetMessage('CRM_SEMA_EMAIL_SUBJECT').': '.$subject."\n\r";
		$eventText .= GetMessage('CRM_SEMA_EMAIL_FROM').': '.$from."\n\r";
		$eventText .= GetMessage('CRM_SEMA_EMAIL_TO').': '.$to."\n\r\n\r";

		$eventBindings = [];
		foreach ($bindings as $item)
		{
			$bindingEntityID = $item['OWNER_ID'];
			$bindingEntityTypeID = $item['OWNER_TYPE_ID'];
			$bindingEntityTypeName = \CCrmOwnerType::resolveName($bindingEntityTypeID);

			$eventBindings["{$bindingEntityTypeName}_{$bindingEntityID}"] = [
				'ENTITY_TYPE' => $bindingEntityTypeName,
				'ENTITY_ID' => $bindingEntityID
			];
		}

		$CCrmEvent->Add(
			[
				'EVENT_TYPE'   => CCrmEvent::TYPE_EMAIL,
				'ENTITY' => $eventBindings,
				'EVENT_ID' => 'MESSAGE',
				'EVENT_TEXT_1' => $eventText,
			]
		);

		$documentType = $this->getDocumentType();
		\CCrmBizProcHelper::sendOperationsAnalytics(
			Dictionary::EVENT_ENTITY_SOCIAL,
			$this,
			$documentType[2] ?? '',
		);

		// <-- Sending Email

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * A file is attached when the template author or the document responsible may download it.
	 * A user id <= 0 is never checked: DiskManager would substitute the current user for it.
	 */
	private function filterDownloadableDiskFiles(array $fileIds, int $responsibleId): array
	{
		$authorId = (int)$this->getTemplateUserId();
		$attachment = [];
		$droppedCount = 0;

		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId > 0 && $this->canDownloadDiskFile($fileId, [$authorId, $responsibleId]))
			{
				$attachment[] = $fileId;
			}
			else
			{
				$droppedCount++;
			}
		}

		if ($droppedCount > 0)
		{
			$this->WriteToTrackingService(
				GetMessage('CRM_SEMA_DISK_ATTACHMENT_DROPPED', ['#COUNT#' => $droppedCount]),
				0,
				CBPTrackingType::Custom
			);
		}

		return $attachment;
	}

	private function canDownloadDiskFile(int $fileId, array $userIds): bool
	{
		foreach (array_unique($userIds) as $userId)
		{
			if ($userId > 0 && \Bitrix\Crm\Integration\DiskManager::checkFileDownloadPermission($fileId, $userId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A string must never reach CFile::MakeFileArray: it reads a local file or downloads a URL for one.
	 */
	private static function makeAttachmentFileArray(mixed $value): ?array
	{
		$fileId = static::normalizeAttachmentFileId($value);
		// MakeFileArray falls back to the path branch for an id missing in b_file
		if ($fileId === null || !CFile::GetFileArray($fileId))
		{
			return null;
		}

		$fileArray = CFile::MakeFileArray($fileId);

		return is_array($fileArray) && !empty($fileArray['tmp_name']) ? $fileArray : null;
	}

	/**
	 * What an expression resolves to is checked on the run only.
	 */
	private static function isAllowedFileAttachmentValue(mixed $value): bool
	{
		return static::normalizeAttachmentFileId($value) !== null || static::isExpression($value);
	}

	private static function normalizeAttachmentFileId(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value, " \t\r\n");
		if (preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return null;
		}

		$fileId = (int)$value;

		return $fileId > 0 ? $fileId : null;
	}

	private function getFromEmail($userId, $from = '')
	{
		$userImap = null;
		$injectUrn = false;
		$reply = '';
		$from = trim((string)$from);
		$crmEmail = \CCrmMailHelper::extractEmail(\COption::getOptionString('crm', 'mail', ''));

		$mailboxes = [];
		if (CModule::includeModule('mail'))
		{
			$mailboxes = \Bitrix\Mail\MailboxTable::getUserMailboxes($userId);
		}

		if ($from === '')
		{
			$availableMailboxes = Main\Mail\Sender::prepareUserMailboxes($userId);
			if (!empty($availableMailboxes))
			{
				$from = reset($availableMailboxes)['email'];
			}

			if ($from === '')
			{
				$from = CUserOptions::GetOption('crm', 'activity_email_addresser', '', $userId);
			}

			$fromData = $this->parseFromString($from);
			$fromEmail = $fromData['email'];
			$fromEncoded = $fromData['nameEncoded'];
		}
		else
		{
			$fromData = $this->parseFromString($from);
			$fromEmail = $fromData['email'];
			$fromEncoded = $fromData['nameEncoded'];

			if (!check_email($fromEmail, true))
			{
				$from = '';
			}
		}

		if (empty($from))
		{
			return false;
		}

		foreach ($mailboxes as $mailbox)
		{
			if ($fromEmail === $mailbox['EMAIL'])
			{
				$userImap = $mailbox;
				break;
			}
		}

		if (empty($userImap))
		{
			if ($crmEmail != '' && $crmEmail != $fromEmail)
			{
				$reply = $fromEmail.', '.$crmEmail;
			}

			$injectUrn = true;
		}

		return [
			'from' => $from,
			'fromEmail' => $fromEmail,
			'userImap' => $userImap,
			'reply' => $reply,
			'injectUrn' => $injectUrn,
			'fromEncoded' => $fromEncoded
		];
	}

	private function isSavedSenderAvailable(int $userId, mixed $rawFromProperty, array $fromInfo): bool
	{
		if (
			!is_string($rawFromProperty)
			|| trim($rawFromProperty) === ''
			|| static::isExpression($rawFromProperty)
			|| !empty($fromInfo['userImap'])
		)
		{
			return true;
		}

		$fromEmail = static::normalizeEmail((string)($fromInfo['fromEmail'] ?? ''));
		$crmEmail = static::normalizeEmail(
			\CCrmMailHelper::extractEmail(\COption::getOptionString('crm', 'mail', ''))
		);
		if ($crmEmail !== '' && $fromEmail === $crmEmail)
		{
			return true;
		}

		return !empty(
			Main\Mail\Sender\UserSenderDataProvider::getUserAvailableSendersByEmail($fromEmail, $userId)
		);
	}

	private static function normalizeEmail(string $email): string
	{
		return (new Main\Mail\Address($email))->getEmail() ?? '';
	}

	private function getToEmail($entityTypeId, $entityId)
	{
		$to = '';
		$comEntityTypeId = $entityTypeId;
		$comEntityId = $entityId;

		$emailType = $this->EmailType;

		$clientCommunications = new ClientCommunications((int)$entityTypeId, (int)$entityId, CCrmFieldMulti::EMAIL);
		$communications = (
			$this->EmailSelectRule === self::SELECT_RULE_LAST
				? $clientCommunications->getLastFilled($emailType ? (string)$emailType : null)
				: $clientCommunications->getFirstFilled($emailType ? (string)$emailType : null)
		);

		if ($communications)
		{
			$email = $communications[0] ?? [];
			if ($email)
			{
				$to = $email['VALUE'];
				$comEntityTypeId = $email['ENTITY_TYPE_ID'];
				$comEntityId = $email['ENTITY_ID'];
			}
		}

		return [$to, $comEntityTypeId, $comEntityId];
	}

	private function parseFromString($from)
	{
		$fromName = $fromEncoded = '';
		$fromEmail = $from;

		$address = new Mail\Address($from);

		if ($address->validate())
		{
			$fromName = $address->getName();
			$fromEmail = $address->getEmail();

			if ($fromName)
			{
				$fromEncoded = $address->getEncoded();
			}
		}

		return [
			'email' => $fromEmail,
			'name' => $fromName,
			'nameEncoded' => $fromEncoded
		];
	}

	public static function ValidateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = [];

		if ($arTestProperties["MessageText"] === "")
		{
			$arErrors[] = ["code" => "NotExist", "parameter" => "MessageText", "message" => GetMessage("CRM_SEMA_EMPTY_PROP")];
		}

		if (($arTestProperties['AttachmentType'] ?? static::ATTACHMENT_TYPE_FILE) !== static::ATTACHMENT_TYPE_DISK)
		{
			$attachment = CBPHelper::MakeArrayFlat((array)($arTestProperties['Attachment'] ?? []));
			foreach ($attachment as $value)
			{
				if (in_array($value, ['', null, 0, '0'], true))
				{
					continue;
				}

				if (!static::isAllowedFileAttachmentValue($value))
				{
					$arErrors[] = [
						'code' => 'InvalidValue',
						'parameter' => 'Attachment',
						'message' => GetMessage('CRM_SEMA_ATTACHMENT_INVALID'),
					];

					break;
				}
			}
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $arCurrentValues = null, $formName = "", $popupWindow = null, $siteId = '')
	{
		if (!CModule::IncludeModule("crm"))
		{
			return '';
		}

		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		Main\Loader::includeModule('crm');

		$map = [
			'Subject' => [
				'Name' => GetMessage('CRM_SEMA_EMAIL_SUBJECT'),
				'Description' => GetMessage('CRM_SEMA_EMAIL_SUBJECT'),
				'FieldName' => 'subject',
				'Type' => 'string',
				'Required' => true
			],
			'MessageText' => [
				'Name' => GetMessage('CRM_SEMA_MESSAGE_TEXT'),
				'FieldName' => 'message_text',
				'Type' => 'text',
				'Required' => true
			],
			'MessageTextType' => [
				'Name' => GetMessage('CRM_SEMA_MESSAGE_TEXT_TYPE'),
				'FieldName' => 'message_text_type',
				'Type' => 'select',
				'Options' => [
					self::TEXT_TYPE_BBCODE => 'BBCODE',
					self::TEXT_TYPE_HTML => 'HTML'
				],
				'Default' => self::TEXT_TYPE_BBCODE
			],
			'MessageTextEncoded' => [
				'Name' => 'MessageTextEncoded',
				'FieldName' => 'message_text_encoded',
				'Type' => 'int',
				'Default' => 0
			],
			'AttachmentType' => [
				'Name' => GetMessage('CRM_SEMA_ATTACHMENT_TYPE_1'),
				'FieldName' => 'attachment_type',
				'Type' => 'select',
				'Options' => [
					static::ATTACHMENT_TYPE_FILE => GetMessage('CRM_SEMA_ATTACHMENT_FILE_2'),
					static::ATTACHMENT_TYPE_DISK => GetMessage('CRM_SEMA_ATTACHMENT_DISK')
				]
			],
			'Attachment' => [
				'Name' => GetMessage('CRM_SEMA_ATTACHMENT_1'),
				'FieldName' => 'attachment',
				'Type' => 'file',
				'Multiple' => true
			],
			'EmailType' => [
				'Name' => GetMessage('CRM_SEMA_EMAIL_TYPE'),
				'FieldName' => 'email_type',
				'Type' => 'select',
				'Options' =>
					['' => GetMessage('CRM_SEMA_EMAIL_TYPE_EMPTY_OPTION')]
					+ \CCrmFieldMulti::GetEntityTypeList(\CCrmFieldMulti::EMAIL)
			],
			'EmailSelectRule' => [
				'Name' => GetMessage('CRM_SEMA_EMAIL_SELECT_RULE'),
				'FieldName' => 'email_select_rule',
				'Type' => 'select',
				'Options' => [
					self::SELECT_RULE_FIRST => GetMessage('CRM_SEMA_EMAIL_SELECT_RULE_FIRST'),
					self::SELECT_RULE_LAST => GetMessage('CRM_SEMA_EMAIL_SELECT_RULE_LAST'),
				],
			],
			'UseLinkTracker' => [
				'Name' => GetMessage('CRM_SEMA_USE_LINK_TRACKER'),
				'FieldName' => 'use_link_tracker',
				'Type' => 'bool',
				'Default' => 'Y'
			],
			'MessageFrom' => [
				'Name' => GetMessage('CRM_SEMA_EMAIL_FROM'),
				'FieldName' => 'message_from',
				'Type' => 'mail_sender',
			]
		];

		if (array_key_exists('Template', $context))
		{
			$map = static::adaptMapToNodeForm($map);
			if (Config\Feature::isEnabled(EmailNodeSenderTemplatesFlag::class))
			{
				$map = static::addEmailTemplateField($map, $context['Properties']['EmailTemplateId'] ?? null);
			}
			$map = array_replace(array_intersect_key(array_flip(self::NODE_FORM_FIELD_ORDER), $map), $map);
		}

		return $map;
	}

	private static function adaptMapToNodeForm(array $map): array
	{
		$map['MessageText']['Getter'] = static function ($dialog, $property, $activity, $compatible = false): string {
			$properties = $activity['Properties'] ?? [];
			$text = (string)($properties['MessageText'] ?? '');

			return (int)($properties['MessageTextEncoded'] ?? 0) === 1 ? static::decodeMessageText($text) : $text;
		};
		unset($map['MessageTextEncoded']);
		unset($map['Subject']['Description']);
		foreach (['MessageTextType', 'EmailType', 'EmailSelectRule', 'AttachmentType', 'UseLinkTracker'] as $key)
		{
			$map[$key]['AllowSelection'] = false;
		}

		if (!Main\Loader::includeModule('disk'))
		{
			unset($map['AttachmentType']['Options'][static::ATTACHMENT_TYPE_DISK]);

			return $map;
		}

		$map['Attachment']['Getter'] = static function ($dialog, $property, $activity, $compatible = false) {
			$properties = $activity['Properties'] ?? [];

			return static::isDiskAttachmentType($properties) ? [] : ($properties['Attachment'] ?? null);
		};

		$map['AttachmentDisk'] = [
			'Name' => $map['Attachment']['Name'],
			'FieldName' => 'attachment_disk',
			'Type' => FieldType::CUSTOM,
			'CustomType' => 'crmSendEmailDisk',
			'Settings' => [
				'chooseLabel' => GetMessage('CRM_SEMA_ATTACHMENT_DISK_CHOOSE') ?? '',
				'removeLabel' => GetMessage('CRM_SEMA_ATTACHMENT_DISK_REMOVE_ARIA') ?? '',
			],
			'Getter' => static function ($dialog, $property, $activity, $compatible = false): array {
				$properties = $activity['Properties'] ?? [];

				return static::isDiskAttachmentType($properties)
					? static::loadDiskFiles((array)($properties['Attachment'] ?? []))
					: []
				;
			},
		];

		return $map;
	}

	private static function isDiskAttachmentType(array $properties): bool
	{
		return ($properties['AttachmentType'] ?? null) === static::ATTACHMENT_TYPE_DISK;
	}

	/**
	 * @return list<array{id: int, name: string}>
	 */
	private static function loadDiskFiles(array $fileIds): array
	{
		$files = [];
		foreach ($fileIds as $fileId)
		{
			$file = (int)$fileId > 0 ? \Bitrix\Disk\File::loadById((int)$fileId) : null;
			if ($file)
			{
				$files[] = ['id' => (int)$file->getId(), 'name' => (string)$file->getName()];
			}
		}

		return $files;
	}

	private static function addEmailTemplateField(array $map, mixed $savedTemplateId): array
	{
		$service = self::getEmailTemplateService();
		$isSavedTemplateUnavailable =
			!\CBPHelper::isEmptyValue($savedTemplateId)
			&& !$service?->isUsable((int)$savedTemplateId)
		;
		$unavailableWarning = GetMessage('CRM_SEMA_EMAIL_TEMPLATE_UNAVAILABLE') ?? '';

		if ($service === null)
		{
			if ($isSavedTemplateUnavailable)
			{
				$map['MessageText']['Warning'] = $unavailableWarning;
			}

			return $map;
		}

		$userId = self::getCurrentUserId();
		$selectorSettings = $service->getSelectorSettings($userId);
		$field = [
			'Name' => GetMessage('CRM_SEMA_EMAIL_TEMPLATE') ?? '',
			'Description' => GetMessage('CRM_SEMA_EMAIL_TEMPLATE_DESCRIPTION') ?? '',
			'AllowSelection' => false,
			'FieldName' => 'email_template_id',
			'Type' => FieldType::ENTITYSELECTOR,
			'Settings' => [
				'entity' => [
					'id' => $selectorSettings->entityId,
					'dynamicLoad' => true,
					'dynamicSearch' => true,
				],
				'enableSearch' => true,
			],
		];

		if ($selectorSettings->editUrlTemplate !== null)
		{
			$field['Settings']['entity']['itemOptions']['default'] = [
				'link' => $selectorSettings->editUrlTemplate,
				'linkTitle' => GetMessage('CRM_SEMA_EMAIL_TEMPLATE_EDIT') ?? '',
			];
			$field['Settings']['openLinkInSlider'] = true;
		}

		if ($selectorSettings->createUrl !== null)
		{
			$field['Settings']['dialogOptions']['footerOptions'] = [
				'label' => GetMessage('CRM_SEMA_EMAIL_TEMPLATE_CREATE') ?? '',
				'url' => $selectorSettings->createUrl,
				'requestMethod' => 'get',
			];
		}

		if ($isSavedTemplateUnavailable)
		{
			$field['Warning'] = $unavailableWarning;
		}
		elseif ($selectorSettings->editUrlTemplate === null || !$service->canView($userId))
		{
			$field['Warning'] = GetMessage('CRM_SEMA_EMAIL_TEMPLATE_NO_ACCESS') ?? '';
		}

		$map['EmailTemplateId'] = $field;

		return $map;
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $arCurrentValues, &$errors)
	{
		$errors = [];

		$properties = [
			'Subject' => (string)($arCurrentValues['subject'] ?? ''),
			'MessageText' => (string)($arCurrentValues['message_text'] ?? ''),
			'MessageTextType' => (string)($arCurrentValues['message_text_type'] ?? ''),
			'AttachmentType' => (string)($arCurrentValues['attachment_type'] ?? ''),
			'MessageFrom' => (string)($arCurrentValues['message_from'] ?? ''),
			'EmailType' => (string)($arCurrentValues['email_type'] ?? ''),
			'EmailSelectRule' => (string)($arCurrentValues['email_select_rule'] ?? ''),
			'UseLinkTracker' => $arCurrentValues['use_link_tracker'] === 'Y' ? 'Y' : 'N',
			'MessageTextEncoded' => 0,
			'Attachment' => [],
			'EmailTemplateId' => null,
		];

		$isSavedTemplateKept = !array_key_exists('email_template_id', $arCurrentValues);
		$emailTemplateId =
			$isSavedTemplateKept
				? self::getSavedEmailTemplateId($arWorkflowTemplate, $activityName)
				: (int)$arCurrentValues['email_template_id']
		;
		if ($emailTemplateId > 0)
		{
			$properties['EmailTemplateId'] = $emailTemplateId;
		}

		$templateId = $properties['EmailTemplateId'];
		if (!static::canCurrentUserChooseEmailTemplate($templateId, $isSavedTemplateKept))
		{
			$errors[] = [
				'code' => 'AccessDenied',
				'parameter' => 'EmailTemplateId',
				'message' => GetMessage('CRM_SEMA_EMAIL_TEMPLATE_NO_ACCESS') ?? '',
			];
		}

		if (
			isset($arCurrentValues['message_from'])
			&& $arCurrentValues['message_from'] === ''
			&& static::isExpression(($arCurrentValues['message_from_text'] ?? null))
		)
		{
			$properties['MessageFrom'] = $arCurrentValues['message_from_text'];
		}

		if ($properties['AttachmentType'] === static::ATTACHMENT_TYPE_DISK)
		{
			foreach ((array)$arCurrentValues["attachment"] as $attachmentId)
			{
				$attachmentId = (int)$attachmentId;
				if ($attachmentId > 0)
				{
					$properties['Attachment'][] = $attachmentId;
				}
			}
		}
		else
		{
			$properties['Attachment'] = $arCurrentValues["attachment"] ?? ($arCurrentValues["attachment_text"] ?? '');
		}

		if (
			$properties['MessageTextType'] !== self::TEXT_TYPE_BBCODE
			&& $properties['MessageTextType'] !== self::TEXT_TYPE_HTML
		)
		{
			$properties['MessageTextType'] = self::TEXT_TYPE_BBCODE;
		}

		if ($properties['MessageTextType'] === self::TEXT_TYPE_HTML)
		{
			$messageText = $properties['MessageText'];
			$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
			$rawData = $request->getPostList()->getRaw('message_text');
			if ($rawData === null)
			{
				$rawData = (array)$request->getPostList()->getRaw('form_data');
				$rawData = $rawData['message_text'] ?? null;
			}

			if ($rawData)
			{
				//TODO: fix for WAF, needs refactoring.
				$messageText = \Bitrix\Crm\Automation\Helper::unConvertExpressions($rawData, $documentType);
			}

			if (!empty($messageText))
			{
				$properties['MessageText'] = self::encodeMessageText($messageText);
				$properties['MessageTextEncoded'] = 1;
			}
		}

		if ($errors)
		{
			return false;
		}

		$errors = self::ValidateProperties($properties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
		if ($errors)
		{
			return false;
		}

		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity["Properties"] = $properties;

		return true;
	}

	private static function canCurrentUserChooseEmailTemplate(?int $templateId, bool $isSavedTemplateKept): bool
	{
		if (\CBPHelper::isEmptyValue($templateId))
		{
			return true;
		}

		$service = self::getEmailTemplateService();
		if (!$service)
		{
			return $isSavedTemplateKept;
		}

		return $service->canView(self::getCurrentUserId());
	}

	private static function getSavedEmailTemplateId(array $workflowTemplate, ?string $activityName): int
	{
		$savedActivity = CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);

		return (int)($savedActivity['Properties']['EmailTemplateId'] ?? 0);
	}

	private static function getEmailTemplateService(): ?EmailTemplateService
	{
		if (!method_exists(EmailTemplateService::class, 'buildLetter'))
		{
			return null;
		}

		$service = new EmailTemplateService();

		return $service->isAvailable() ? $service : null;
	}

	private static function getCurrentUserId(): int
	{
		return (int)Main\Engine\CurrentUser::get()->getId();
	}

	private function getSubject()
	{
		$subject = $this->Subject;
		if (is_array($subject))
		{
			$subject = implode(', ', \CBPHelper::MakeArrayFlat($subject));
		}

		return $subject;
	}

	private function getUsableEmailTemplateId(): ?int
	{
		$templateId = $this->EmailTemplateId;
		if (\CBPHelper::isEmptyValue($templateId))
		{
			return null;
		}

		$templateId = (int)$templateId;

		return self::getEmailTemplateService()?->isUsable($templateId) ? $templateId : null;
	}

	private static function convertMessageToHtml($message, $messageType)
	{
		if ($messageType === self::TEXT_TYPE_HTML)
		{
			return $message;
		}

		$parser = new CTextParser();
		$parser->allow['SMILES'] = 'N';

		return $parser->convertText($message);
	}

	private function getMessageText($messageType)
	{
		$message = $this->getRawProperty('MessageText');
		if ($this->MessageTextEncoded)
		{
			$message = self::decodeMessageText($message);
		}

		return $this->ParseValue(
			$message,
			'text',
			function($objectName, $fieldName, $property, $result) use ($messageType)
			{
				$result = CBPHelper::stringify($result);

				if ($messageType === 'html')
				{
					$contentType =
						$property['ValueContentType'] ?? (($property['Type'] ?? '') === 'S:HTML' ? 'html' : 'text')
					;
					if ($contentType === 'bb')
					{
						$result = Crm\Format\TextHelper::sanitizeHtml(
							Crm\Format\TextHelper::convertBbCodeToHtml($result)
						);
					}
					elseif ($contentType !== 'html')
					{
						$result = htmlspecialcharsbx($result);
					}
				}

				return $result;
			}
		);
	}

	public function collectUsages()
	{
		$properties = $this->arProperties;
		$message = $this->getRawProperty('MessageText');
		if ($this->MessageTextEncoded)
		{
			$message = self::decodeMessageText($message);
			$properties['MessageText'] = $message;
		}

		$usages = [];
		$this->collectUsagesRecursive($properties, $usages);

		return $usages;
	}

	private static function encodeMessageText($text)
	{
		return 'base64,'.base64_encode($text);
	}

	public static function decodeMessageText($text)
	{
		if (mb_strpos($text, 'base64,') === 0)
		{
			$text = mb_substr($text, 7);

			return base64_decode($text);
		}

		//compatible encode type
		return htmlspecialcharsback($text);
	}

	private function writeError($errorText, $userId)
	{
		$this->WriteToTrackingService($errorText, 0, CBPTrackingType::Error);
		$timelineText = GetMessage('CRM_SEMA_TIMELINE_ERROR', ['#ERROR_TEXT#' => $errorText]);
		\Bitrix\Crm\Timeline\BizprocController::getInstance()->onActivityError($this, $userId, $timelineText);
	}

	private function logDebug(array $values = [])
	{
		if (!$this->workflow->isDebug())
		{
			return;
		}

		$fullMap = static::getPropertiesMap($this->getDocumentType());
		$map = [
			'MessageFrom' => $fullMap['MessageFrom']['Name'],
			'MessageTo' => GetMessage('CRM_SEMA_EMAIL_TO'),
			'Subject' => $fullMap['Subject'],
		];

		$debugInfo = $this->getDebugInfo($values, $map);
		$this->writeDebugInfo($debugInfo);
	}

	private function logDebugActivity($id)
	{
		if (!$this->workflow->isDebug())
		{
			return;
		}

		$pathTemplate = Config\Option::get(
			'crm',
			'path_to_activity_show',
			SITE_DIR . 'crm/activity/?ID=#activity_id#&open_view=#activity_id#'
		);
		$value = CComponentEngine::makePathFromTemplate($pathTemplate, ['activity_id' => $id]);
		$value = (new Main\Web\Uri($value))->addParams(['open_view' => $id])->getUri();

		$toWrite = [
			'propertyName' => GetMessage("CRM_SEMA_MESSAGE_TEXT"),
			'propertyValue' => $value,
			'propertyLinkName' => GetMessage('CRM_SEMA_ACTIVITY_LINK_LABEL'),
		];

		$this->writeDebugTrack(
			$this->getWorkflowInstanceId(),
			$this->getName(),
			$this->executionStatus,
			$this->executionResult,
			$this->Title ?? '',
			$toWrite,
			CBPTrackingType::DebugLink
		);
	}
}
