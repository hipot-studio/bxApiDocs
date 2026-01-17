<?php

namespace Bitrix\Crm\Recurring\Mail;

use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Disk\Driver;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmActivityDirection;
use CCrmActivityEmailSender;
use CCrmActivityNotifyType;
use CCrmActivityPriority;
use CCrmActivityType;
use CCrmContentType;
use CCrmFieldMulti;
use CCrmMailTemplate;
use CCrmOwnerType;
use CCrmTemplateManager;

abstract class BaseSender
{
	use Singleton;

	protected array $itemData = [];
	protected array $dataTo = [];
	protected array $dataFrom = [];
	protected array $templateData = [];

	public function setData(array $itemData = [], array $sendData = [], ?int $mailTemplateId = null): Result
	{
		$this->setItemData($itemData);

		$itemId = $this->getItemId($itemData);

		if ($itemId <= 0)
		{
			return (new Result())->addError(new Error('Item data not found'));
		}

		$fillDataFromResult = $this->fillDataFrom($itemData);
		if (!$fillDataFromResult->isSuccess())
		{
			return $fillDataFromResult;
		}

		$fillDataToResult = $this->fillDataTo($sendData);
		if (!$fillDataToResult->isSuccess())
		{
			return $fillDataToResult;
		}

		$this->fillTemplateData($mailTemplateId);

		return new Result();
	}

	private function setItemData(array $itemData): void
	{
		$this->itemData = $itemData;
	}

	abstract protected function getMyCompanyId(array $itemData): ?int;

	private function getItemId(array $itemData): ?int
	{
		return $itemData['ID'] ? (int)$itemData['ID'] : null;
	}

	protected function fillDataFrom(array $itemData): Result
	{
		$myCompanyId = $this->getMyCompanyId($itemData);

		if ($myCompanyId <= 0)
		{
			return (new Result())->addError(new Error('MyCompanyId not found'));
		}

		if (
			empty($this->dataFrom['ELEMENT_ID'])
			|| $this->dataFrom['ELEMENT_ID'] === $myCompanyId
			|| empty($this->dataFrom['TYPE_ID'])
		)
		{
			$result = $this->prepareDataFrom($myCompanyId);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$this->dataFrom = $result->getData();
		}

		return new Result();
	}

	protected function prepareDataFrom(int $elementId): Result
	{
		$sort = ['ID' => 'asc'];
		$filter = [
			'=ELEMENT_ID' => $elementId,
			'=ENTITY_ID' => 'COMPANY',
			'=TYPE_ID' => 'EMAIL',
		];

		$ownerData = CCrmFieldMulti::GetList($sort, $filter)->Fetch();
		$result = new Result();

		if (!$ownerData || !check_email($ownerData['VALUE']))
		{
			$result->addError(new Error('Mail sending error. Owner Email is not found'));

			return $result;
		}

		if (isset($ownerData['ENTITY_ID']))
		{
			$ownerTypeName = mb_strtoupper((string)$ownerData['ENTITY_ID']);
		}
		else
		{
			$result->addError(new Error('Mail sending error. Owner type entity is not defined!'));

			return $result;
		}

		$ownerTypeId = CCrmOwnerType::ResolveID($ownerTypeName);
		if (!CCrmOwnerType::IsDefined($ownerTypeId))
		{
			$result->addError(new Error('Mail sending error. Owner type is not supported!'));

			return $result;
		}
//array ('ID' => '12233', 'ENTITY_ID' => 'COMPANY', 'ELEMENT_ID' => '2082', 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'WORK', 'COMPLEX_ID' => 'EMAIL_WORK', 'VALUE' => 'atoll-it@mail.ru',)
		return $result->setData([
			'ELEMENT_ID' => (int)$ownerData['ELEMENT_ID'],
			'VALUE' => htmlspecialcharsbx($ownerData['VALUE']),
			'TYPE_ID' => $ownerTypeId,
		]);
	}

	protected function fillDataTo(array $sendData): Result
	{
		$result = $this->prepareDataTo($sendData);
		if ($result->isSuccess())
		{
			$this->dataTo = $result->getData();
		}

		return $result;
	}

	private function prepareDataTo(array $data): Result
	{
		$result = new Result();

		$resultData = [];

		foreach ($data as $item)
		{
			$bindings = [];

			if (!check_email($item['VALUE']))
			{
				$result->addError(new Error(Loc::getMessage('CRM_RECURRING_MAIL_INVALID_EMAIL')));

				continue;
			}

			$entityTypeId = CCrmOwnerType::ResolveID($item['ENTITY_ID']);
			$entityId = (int)$item['ELEMENT_ID'];

			$communicationItem = [
				'ID' => (int)$item['ID'],
				'TYPE' => $item['TYPE_ID'],
				'VALUE' => htmlspecialcharsbx($item['VALUE']),
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
			];
			CCrmActivity::PrepareCommunicationInfo($communicationItem);

			$bindings[$item['COMPLEX_ID'] . '_' . $item['ENTITY_ID']] = [
				'OWNER_TYPE_ID' => $entityTypeId,
				'OWNER_ID' => $entityId,
			];

			$resultData[] = [
				'COMMUNICATIONS' => $communicationItem,
				'BINDINGS' => $bindings,
			];
		}

		return $result->setData($resultData);
	}

	protected function fillTemplateData(?int $mailTemplateId): void
	{
		$templateId = (int)($this->templateData['ID'] ?? 0);
		if ($templateId === $mailTemplateId)
		{
			return;
		}

		$this->templateData = [];
		if ($mailTemplateId <= 0)
		{
			return;
		}

		$templateData = CCrmMailTemplate::GetByID($mailTemplateId);
		if ($templateData)
		{
			$this->templateData = $templateData;
		}
	}

	protected function getHostUrl(): string
	{
		return UrlManager::getInstance()->getHostUrl();
	}

	protected function getTemplateAttachedFiles(): array
	{
		$attachments = [];
		$templateId = $this->getTemplateDataId();

		if ($templateId <= 0 || StorageType::getDefaultTypeId() !== StorageType::Disk)
		{
			return $attachments;
		}

		global $USER_FIELD_MANAGER;
		$files = $USER_FIELD_MANAGER->getUserFieldValue(
			'CRM_MAIL_TEMPLATE',
			'UF_ATTACHMENT',
			$templateId,
		);

		if (empty($files) || !is_array($files))
		{
			return $attachments;
		}

		$diskUfManager = Driver::getInstance()->getUserFieldManager();
		$diskUfManager->loadBatchAttachedObject($files);
		foreach ($files as $attachedId)
		{
			if ($attachedObject = $diskUfManager->getAttachedObjectById($attachedId))
			{
				$attachments[] = [
					'fileId' => $attachedId,
					'objectId' => $attachedObject->getObjectId(),
				];
			}
		}

		return $attachments;
	}

	protected function getTemplateDataId(): ?int
	{
		return isset($this->templateData['ID']) ? (int)$this->templateData['ID'] : null;
	}

	protected function fillEmailMessage(array $invoice): array
	{
		return [
			'SUBJECT' => $this->fillMessageSubject($invoice),
			'BODY' => $this->fillMessageBody($invoice),
		];
	}

	protected function fillMessageSubject(array $itemData): string
	{
		$subject = isset($this->templateData['SUBJECT']) ? (string)($this->templateData['SUBJECT']) : '';
		$entityTypeId = $this->getEntityTypeId();

		if ($subject !== '' && $entityTypeId)
		{
			$subject = CCrmTemplateManager::PrepareTemplate(
				$subject,
				$entityTypeId,
				$itemData['ID'],
				CCrmContentType::Html,
				$this->getResponsibleId(),
			);
		}

		if ($subject === '')
		{
			$subject = $this->getDefaultMessageSubject($itemData);

			if ($itemData['ORDER_TOPIC'] !== '')
			{
				$subject .= ' - ' . $itemData['ORDER_TOPIC'];
			}
		}

		return $subject;
	}

	protected function getDefaultMessageSubject(array $itemData): string
	{
		$accountNumber = $itemData['ACCOUNT_NUMBER'] ?? '';

		return Loc::getMessage(
			'CRM_RECURRING_MAIL_DEFAULT_EMAIL_SUBJECT',
			[
				'#ACCOUNT_NUMBER#'=> empty($accountNumber) ? $itemData['ID'] : $itemData['ACCOUNT_NUMBER'],
			],
		);
	}

	protected function fillMessageBody(array $itemData): string
	{
		$body = isset($this->templateData['BODY']) ? (string)($this->templateData['BODY']) : '';
		$entityTypeId = $this->getEntityTypeId();

		if (!empty($body))
		{
			if ($this->templateData['BODY_TYPE'] === CCrmContentType::BBCode)
			{
				$bbCodeParser = new \CTextParser();
				$body = $bbCodeParser->convertText($body);
			}

			if ($entityTypeId)
			{
				$body = CCrmTemplateManager::prepareTemplate(
					$body,
					$entityTypeId,
					$itemData['ID'],
					CCrmContentType::Html,
					$this->getResponsibleId(),
				);
			}
		}

		CCrmActivity::AddEmailSignature($body, CCrmContentType::BBCode);

		if (empty($body))
		{
			$body = Loc::getMessage('CRM_RECURRING_MAIL_EMPTY_BODY_MESSAGE');
		}

		return $body;
	}

	abstract protected function getEntityTypeId(): ?int;

	protected function trySendEmail(int $activityId, array &$fields): Result
	{
		$sendErrors = [];
		$result = new Result();

		if (CCrmActivityEmailSender::TrySendEmail($activityId, $fields, $sendErrors))
		{
			return $result;
		}

		$errorList = [];
		foreach ($sendErrors as $error)
		{
			$code = $error['CODE'];
			if ($code === CCrmActivityEmailSender::ERR_CANT_LOAD_SUBSCRIBE)
			{
				$errors[] = 'Email send error. Failed to load module "subscribe".';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_INVALID_DATA)
			{
				$errors[] = 'Email send error. Invalid data.';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_INVALID_EMAIL)
			{
				$errors[] = 'Email send error. Invalid email is specified.';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_CANT_FIND_EMAIL_FROM)
			{
				$errors[] = 'Email send error. "From" is not found.';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_CANT_FIND_EMAIL_TO)
			{
				$errors[] = 'Email send error. "To" is not found.';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_CANT_ADD_POSTING)
			{
				$errors[] = 'Email send error. Failed to add posting. Please see details below.';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_CANT_SAVE_POSTING_FILE)
			{
				$errors[] = 'Email send error. Failed to save posting file. Please see details below.';
			}
			elseif ($code === CCrmActivityEmailSender::ERR_CANT_UPDATE_ACTIVITY)
			{
				$errors[] = 'Email send error. Failed to update activity.';
			}
			else
			{
				$errors[] = 'Email send error. General error.';
			}

			$msg = $error['MESSAGE'] ?? '';
			if ($msg !== '')
			{
				$errors[] = $msg;
			}

			foreach ($errors as $errorMsg)
			{
				$errorList[] = new Error($errorMsg);
			}
		}

		return $result->addErrors($errorList);
	}

	protected function getFields(array $message, array $attachments): array
	{
		$now = new DateTime();

		return [
			'OWNER_ID' => $this->dataFrom['ID'],
			'OWNER_TYPE_ID' => $this->dataFrom['TYPE_ID'],
			'TYPE_ID' =>  CCrmActivityType::Email,
			'SUBJECT' => $message['SUBJECT'],
			'START_TIME' => $now,
			'END_TIME' => $now,
			'COMPLETED' => 'Y',
			'RESPONSIBLE_ID' => $this->getResponsibleId(),
			'PRIORITY' => CCrmActivityPriority::Medium,
			'DESCRIPTION' => $message['BODY'],
			'DESCRIPTION_TYPE' => CCrmContentType::Html,
			'DIRECTION' => CCrmActivityDirection::Outgoing,
			'LOCATION' => '',
			'SETTINGS' => ['MESSAGE_FROM' => $this->dataFrom['VALUE']],
			'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
			'STORAGE_TYPE_ID' => StorageType::getDefaultTypeID(),
			'STORAGE_ELEMENT_IDS' => $attachments,
		];
	}

	abstract protected function getResponsibleId(): int;

	protected function addEventToStatFile(int $recurringId, array $fields): void
	{
		addEventToStatFile(
			'crm',
			'send_email_message',
			sprintf('recurring_%s_%s', $this->getEntityTypeId(), $recurringId),
			trim(trim($fields['SETTINGS']['MESSAGE_HEADERS']['Message-Id']), '<>'),
		);
	}

	protected function serveInlineAttachments(array &$attachments, array &$message): void
	{
		$attachedFiles = $this->getTemplateAttachedFiles();

		foreach ($attachedFiles as $attachedFile)
		{
			$objectId = $attachedFile['objectId'];
			$attachments[] = $attachedFile['objectId'];

			$message['BODY'] = str_replace(
				sprintf('bxacid:%u', $attachedFile['fileId']),
				sprintf('bxacid:n%u', $objectId),
				$message['BODY'],
			);
		}
	}
}
