<?php

namespace Bitrix\Crm\Recurring\Mail;

use Bitrix\Crm\Integration\StorageManager;
use Bitrix\Crm\Recurring\Entity\DynamicRecurringDocumentTable;
use Bitrix\Crm\Service\Container;
use Bitrix\DocumentGenerator\Document;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Internal\Sender;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\Result;
use CCrmActivity;
use CCrmFieldMulti;
use CCrmOwnerType;
use RuntimeException;

class DynamicSender extends BaseSender
{
	protected ?int $documentId = null;
	protected array $recurringDocumentData = [];
	protected ?int $entityTypeId = null;

	public function setEntityTypeId(int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	protected function getMyCompanyId(array $itemData): ?int
	{
		return $itemData['MYCOMPANY_ID'] ? (int)$itemData['MYCOMPANY_ID'] : null;
	}

	public function loadRecurringDocumentData(int $documentId): ?self
	{
		$this->documentId = $documentId;

		$recurringDocument = DynamicRecurringDocumentTable::query()
			->setSelect(['*', 'RECURRING.EMAIL_IDS', 'RECURRING.PARAMS'])
			->setFilter(['=DOCUMENT_ID' => $this->documentId])
			->exec()
			->fetchObject()
		;

		if (!$recurringDocument)
		{
			return null;
		}

		$this->recurringDocumentData = [
			'id' => $recurringDocument->getId(),
			'entityTypeId' => $recurringDocument->getEntityTypeId(),
			'entityId' => $recurringDocument->getEntityId(),
			'emailTemplateId' => $recurringDocument->getEmailTemplateId(),
			'recurringItemId' => $recurringDocument->getRecurringItemId(),
			'emailIds' => $recurringDocument->getRecurring()->getEmailIds(),
			'params' => $recurringDocument->getRecurring()->getParams(),
		];

		return $this;
	}

	public function sendMail(): Result
	{
		if ($this->documentId === null)
		{
			throw new RuntimeException('Need call loadRecurringDocumentData before');
		}

		if (empty($this->recurringDocumentData))
		{
			return (new Result())->addError(new Error('Mail sending error. Recurring document data are empty!'));
		}

		if (!Loader::includeModule('documentgenerator'))
		{
			return (new Result())->addError(new Error('Mail sending error. Document generator module is not installed!'));
		}

		$document = Document::loadById($this->documentId);
		if (!$document)
		{
			return (new Result())->addError(new Error('Mail sending error. Document not found!'));
		}

		$fileId = $document->getEmailDiskFile();
		if ($fileId <= 0)
		{
			return (new Result())->addError(new Error('Mail sending error. Document file not found!'));
		}
		$attachments = [$fileId];

		$itemData = $this->getItemData();
		if (!$itemData)
		{
			return (new Result())->addError(new Error('Mail sending error. Item data are empty!'));
		}

		$emails = $this->getEmails();
		$templateId = $this->getTemplateId();

		$this->setData($itemData, $emails, $templateId);

		$message = $this->fillEmailMessage($itemData);

		$this->serveInlineAttachments($attachments, $message);

		$fields = $this->getFields($message, $attachments);

		$result = $this->sendEmailToRecipientsWithTimelineEntry($fields, $attachments);

		if ($result->isSuccess())
		{
			DynamicRecurringDocumentTable::delete($this->recurringDocumentData['id']);
		}

		return $result;
	}

	private function sendEmailToRecipientsWithTimelineEntry(array $data, array $attachments): Result
	{
		$result = new Result();

		$activityIds = [];

		foreach ($this->dataTo as $dataToItem)
		{
			$fields = $data;

			$descriptionUpdated = false;

			$fields['COMMUNICATIONS'] = [$dataToItem['COMMUNICATIONS']];
			$fields['BINDINGS'] = array_values($dataToItem['BINDINGS']);

			$fields['BINDINGS'][] = [
				'OWNER_TYPE_ID' => $this->getEntityTypeId(),
				'OWNER_ID' => $this->itemData['ID'],
			];

			$id = CCrmActivity::Add(
				$fields,
				false,
				false,
				['REGISTER_SONET_EVENT' => true],
			);
			if (!$id)
			{
				$result->addError(new Error(CCrmActivity::GetLastErrorMessage()));

				return $result;
			}

			$activityIds[] = $id;

			CCrmActivity::SaveCommunications($id, $fields['COMMUNICATIONS'], $fields);

			$description = $fields['DESCRIPTION'];

			foreach ($attachments as $item)
			{
				$fileInfo = StorageManager::getFileInfo(
					$item,
					$fields['STORAGE_TYPE_ID'],
					false,
					[
						'OWNER_TYPE_ID' => CCrmOwnerType::Activity,
						'OWNER_ID' => $id,
					],
				);

				$description = str_replace(
					sprintf('bxacid:n%u', $item),
					htmlspecialcharsbx($fileInfo['VIEW_URL']),
					$description,
					$count,
				);

				if ($count > 0)
				{
					$descriptionUpdated = true;
				}

				$fileArray = StorageManager::makeFileArray($item, $fields['STORAGE_TYPE_ID']);

				$contentId = sprintf(
					'bxacid.%s@%s.crm',
					hash('crc32b', $fileArray['external_id'] . $fileArray['size'] . $fileArray['name']),
					hash('crc32b', $this->getHostUrl()),
				);
				$fields['DESCRIPTION'] = str_replace(
					sprintf('bxacid:n%u', $item),
					sprintf('cid:%s', $contentId),
					$fields['DESCRIPTION'],
				);
			}

			if ($descriptionUpdated)
			{
				CCrmActivity::update(
					$id,
					['DESCRIPTION' => $description],
					false,
					false,
					['REGISTER_SONET_EVENT' => true],
				);
			}

			$sendEmailResult = $this->trySendEmail($id, $fields);
			if (!$sendEmailResult->isSuccess())
			{
				return $sendEmailResult;
			}

			$this->addEventToStatFile($this->recurringDocumentData['recurringItemId'], $fields);
		}

		return $result->setData(['ACTIVITY_IDS' => $activityIds]);
	}

	protected function fillDataFrom(array $itemData): Result
	{
		$senderId = $this->recurringDocumentData['params']['SENDER_ID'] ?? 0;
		if ($senderId <= 0)
		{
			return (new Result())->addError(new Error('senderId not found'));
		}

		$sender = SenderTable::getById($senderId)->fetchObject();
		if (!$sender)
		{
			return (new Result())->addError(new Error('Sender not found'));
		}

		$this->dataFrom = [
			'SENDER_ID' => $senderId,
			'ID' => $this->itemData['ID'],
			'TYPE_ID' => $this->getEntityTypeId(),
		];

		$this->dataFrom['VALUE'] = htmlspecialcharsbx($sender->getEmail());

		return new Result();
	}

	private function getSender(int $senderId): Sender
	{
		return SenderTable::getById($senderId)->fetchObject();
	}


	private function getItemData(): ?array
	{
		$entityTypeId = $this->recurringDocumentData['entityTypeId'];
		$entityId = $this->recurringDocumentData['entityId'];

		return Container::getInstance()->getFactory($entityTypeId)?->getItem($entityId)?->getCompatibleData();
	}

	private function getEmails(): array
	{
		$emails = [];
		$emailIds = $this->recurringDocumentData['emailIds'];
		$emailFieldsData = CCrmFieldMulti::GetListEx(
			['ID' => 'asc'],
			[
				'@ID' => $emailIds,
				'=TYPE_ID' => 'EMAIL',
			],
		);
		while ($email = $emailFieldsData->Fetch())
		{
			$emails[$email['ID']] = $email;
		}

		return $emails;
	}

	private function getTemplateId(): ?int
	{
		return $this->recurringDocumentData['emailTemplateId'] ?: null;
	}

	protected function getEntityTypeId(): ?int
	{
		return \CCrmOwnerType::isUseDynamicTypeBasedApproach($this->entityTypeId) ? $this->entityTypeId : null;
	}

	protected function getResponsibleId(): int
	{
		return (int)($this->itemData['ASSIGNED_BY_ID'] ?? 0);
	}
}
