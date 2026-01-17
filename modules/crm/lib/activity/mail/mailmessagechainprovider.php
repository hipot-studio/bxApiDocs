<?php

namespace Bitrix\Crm\Activity\Mail;

use Bitrix\Crm\Activity\Mail\Message;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Disk\File;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Main\ErrorCollection;
use Bitrix\Mobile\UI;
use Bitrix\UI\EntitySelector\ItemCollection;
use Bitrix\UI\EntitySelector\Item;

class MailMessageChainProvider
{
	protected const PERMISSION_READ = 1;
	protected const SUPPORTED_ACTIVITY_TYPE = 'CRM_EMAIL';

	public ErrorCollection $errorCollection;

	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
	}

	private function checkActivityIsType(array $activity, string $type = self::SUPPORTED_ACTIVITY_TYPE): bool
	{
		$provider = \CCrmActivity::getActivityProvider($activity);

		if (!$provider)
		{
			return false;
		}

		if ($provider::getId() === $type)
		{
			return true;
		}

		return false;
	}

	private function getActivities(array $filters, string $activityType, array $select = [], array $order = [], int $limit = 50): array
	{
		$processedActivities = [];

		$requiredFieldsForChecks = [
			'ID',
			'TYPE_ID',
			'PROVIDER_ID',
			'OWNER_TYPE_ID',
			'OWNER_ID',
		];

		$activities = ActivityTable::getList([
			'select' => array_merge($select, $requiredFieldsForChecks),
			'filter' => $filters,
			'order' => $order,
			'limit' => $limit,
		])->fetchAll();

		foreach ($activities as &$activity)
		{
			if ($this->checkActivityIsType($activity, $activityType))
			{
				\CCrmActivity::PrepareStorageElementIDs($activity);
				$processedActivities[] = $activity;
			}
			else
			{
				$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_CAN_NOT_DETERMINE_THE_MESSAGE_FORMAT'));
			}
		}

		return $processedActivities;
	}

	protected function checkActivityPermission(int $permission = self::PERMISSION_READ, array $activities = []): bool
	{
		if (count($activities) === 0)
		{
			return false;
		}

		$activity = $activities[0];

		if (!isset($activity['OWNER_TYPE_ID']) || !isset($activity['OWNER_ID']))
		{
			return false;
		}

		$ownerTypeId = $activity['OWNER_TYPE_ID'];
		$ownerId = $activity['OWNER_ID'];

		if ($permission === self::PERMISSION_READ)
		{
			if (\CCrmActivity::CheckReadPermission($ownerTypeId, $ownerId))
			{
				return true;
			}
		}

		return false;
	}

	protected function getHeaderMessage(array $activity): array
	{
		$headerResult = Message::getHeader($activity);
		$this->errorCollection->add($headerResult->getErrors());

		return $headerResult->getData();
	}

	public function getMessage(int $id, bool $takeBody = false, bool $takeFiles = false): MailMessage
	{
		$message = new MailMessage();

		if ($takeBody)
		{
			$message->body = $this->getMessageBody($id);
		}

		if ($takeFiles)
		{
			$message->attachments = $this->getMessageFilesLinkMessages($id)['FILES'];
		}

		return $message;
	}

	private function getMessageBody($id): ?string
	{
		$userPermissions = \CCrmPerms::GetCurrentUserPermissions();

		if (!Container::getInstance()->getUserPermissions($userPermissions->GetUserID())->entityType()->canReadSomeItemsInCrm())
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return null;
		}

		$body = null;

		$activities = $this->getActivities(
			[
				'ID' => $id,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			[
				'DESCRIPTION',
			]
		);

		if (!$this->checkActivityPermission(self::PERMISSION_READ, $activities))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return null;
		}

		$activity = $activities[0];

		if (is_array($activity))
		{
			Email::uncompressActivity($activity);

			if (isset($activity['DESCRIPTION']))
			{
				$body = (string)$activity['DESCRIPTION'];
			}
		}

		return $body;
	}

	protected function getMessageFilesLinkMessages(int $id, bool $forMobile = true): array
	{
		$activities = $this->getActivities(
			[
				'ID' => $id,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			[
				'STORAGE_ELEMENT_IDS',
			]
		);

		if (!$this->checkActivityPermission(self::PERMISSION_READ, $activities))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return [];
		}

		if (count($activities) === 0)
		{
			return [];
		}

		$activity = $activities[0];

		$filesInfo = [
			'ID' => $activity['ID'],
			'FILES' => [],
		];

		$filesIDs = [];

		if(is_array($activity['STORAGE_ELEMENT_IDS']))
		{
			$filesIDs = array_unique($activity['STORAGE_ELEMENT_IDS'], SORT_NUMERIC);
		}

		foreach ($filesIDs as $fileID)
		{
			if($forMobile)
			{
				$file = File::loadById($fileID);
				if($file)
				{
					if (\Bitrix\Main\Loader::includeModule('mobile'))
					{
						$diskFileInfo = UI\File::loadWithPreview($file->getFileId());

						if($diskFileInfo)
						{
							$filesInfo['FILES'][] = $diskFileInfo->getInfo();
						}
					}
				}
			}
			else
			{
				$diskFileInfo = \Bitrix\Crm\Integration\DiskManager::getFileInfo(
					(int)$fileID,
					false,
					[
						'OWNER_TYPE_ID' => \CCrmOwnerType::Activity,
						'OWNER_ID' => $activity['ID'],
					]
				);

				if ($diskFileInfo)
				{
					$fileName = explode(".", $diskFileInfo['NAME']);
					$clearedInfo = [
						'ID' => (int)$fileID,
						'NAME' => $diskFileInfo['NAME'],
						'VIEW_URL' => $diskFileInfo['VIEW_URL'],
						'PREVIEW_URL' => $diskFileInfo['PREVIEW_URL'],
						'TYPE' => end($fileName),
					];

					$filesInfo['FILES'][] = $clearedInfo;
				}
			}
		}

		return $filesInfo;
	}

	private function convertToMailContactList(array $contacts, bool $isUser = false): ItemCollection
	{
		$list = new ItemCollection();

		if (empty($contacts))
		{
			return $list;
		}

		foreach ($contacts as $contact)
		{
			$list->add(new Item([
				'id' => (string)$contact['id'],
				'customData'=>  [
					'isUser' => $isUser,
					'typeName' => (string)($contact['typeName'] ?? ''),
					'typeNameId' => (string)($contact['typeNameId'] ?? ''),
					'name' => (string)$contact['name'],
					'email' => (string)$contact['email'],
				],
			]));
		}

		return $list;
	}

	public function getChain(int $messageId): MailMessageChain
	{
		$mailMessageChain = new MailMessageChain();

		$userPermissions = \CCrmPerms::GetCurrentUserPermissions();

		if (!Container::getInstance()->getUserPermissions($userPermissions->GetUserID())->entityType()->canReadSomeItemsInCrm())
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return $mailMessageChain;
		}

		$activities = $this->getActivities(
			[
				'ID' => $messageId,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			[
				'THREAD_ID',
			],
			limit: 1
		);

		if (count($activities) === 0)
		{
			return $mailMessageChain;
		}

		$activity = $activities[0];

		if (!isset($activity['OWNER_TYPE_ID']) || !isset($activity['OWNER_ID']))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_CAN_NOT_DETERMINE_THE_MESSAGE_FORMAT'));

			return $mailMessageChain;
		}

		if (!$this->checkActivityPermission(self::PERMISSION_READ, $activities))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return $mailMessageChain;
		}

		$activity = $activities[0];

		$threadId = $activity['THREAD_ID'];
		$select = [
			'SETTINGS',
			'PARENT_ID',
			'THREAD_ID',
			'SUBJECT',
			'START_TIME',
			'DIRECTION',
		];

		$order = [
			'START_TIME' => 'DESC',
		];

		/*
			We need to make two selections and sort the messages by date
			in order to limit the number of messages in long chains(in the future)
			while preserving the open email in the middle of the chain.
		 */
		$messageBeforeCurrent = $this->getActivities(
			[
				'=THREAD_ID' => $threadId,
				'<=PARENT_ID' => $messageId,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			$select,
			$order
		);

		$messageAfterCurrent = $this->getActivities(
			[
				'=THREAD_ID' => $threadId,
				'>PARENT_ID' => $messageId,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			$select,
			$order
		);

		$chainActivities = array_merge($messageBeforeCurrent, $messageAfterCurrent);

		/*
			We need to sort the messages by time again.
			For example, message number 5 may be a response to the first message, not the fourth.
		*/
		usort($chainActivities, function($a, $b) {
			return $b['START_TIME']->getTimestamp() <=> $a['START_TIME']->getTimestamp();
		});

		$lastIncomingId = null;
		$lastIncomingKey = null;

		foreach ($chainActivities as $index => $item)
		{
			$message = new MailMessage();
			$message->id = (int)$item['ID'];
			$message->subject = $item['SUBJECT'];
			$message->date =  $item['START_TIME']->getTimestamp();
			$message->ownerTypeId = (int)$item['OWNER_TYPE_ID'];
			$message->ownerId = (int)$item['OWNER_ID'];
			$message->ownerType = \CCrmOwnerType::ResolveName($item['OWNER_TYPE_ID']);
			$message->direction = (int)$item['DIRECTION'];

			$header = $this->getHeaderMessage($item);

			$message->availableSenders = $this->convertToMailContactList($header['accessMailboxesForSending'] ?? [], true);
			$message->employees = $this->convertToMailContactList($header['employeeEmails'] ?? [], true);
			$message->from = $this->convertToMailContactList($header['from'] ?? []);
			$message->replyTo = $this->convertToMailContactList($header['replyTo'] ?? []);
			$message->cc = $this->convertToMailContactList($header['cc'] ?? []);
			$message->bcc = $this->convertToMailContactList($header['bcc'] ?? []);
			$message->to = $this->convertToMailContactList($header['to'] ?? []);

			if ($item['ID'] == $messageId)
			{
				/*
				 * Load the body only for the selected message in the chain
				 */
				$message->body = $this->getMessageBody($messageId);

				if (is_null($message->body))
				{
					$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));
				}

				$message->attachments = $this->getMessageFilesLinkMessages($messageId)['FILES'];
			}

			$mailMessageChain->list[] = $message;

			if (is_null($lastIncomingId) && $message->direction === MailMessage::DIRECTION_INCOMING)
			{
				$lastIncomingId = (int)$item['ID'];
				$lastIncomingKey = $index;
			}
		}

		/*
			Upload the content of the last read message to be able to transfer it to the sending component for citation
		 */
		if (!is_null($lastIncomingKey))
		{
			$mailMessageChain->list[$lastIncomingKey]->body = $this->getMessageBody($lastIncomingId);
			$mailMessageChain->list[$lastIncomingKey]->attachments = $this->getMessageFilesLinkMessages($lastIncomingId)['FILES'];
		}

		$mailMessageChain->properties = [
			'lastIncomingId' => $lastIncomingId,
		];

		return $mailMessageChain;
	}
}