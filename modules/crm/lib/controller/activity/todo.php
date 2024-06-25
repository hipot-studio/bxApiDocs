<?php

namespace Bitrix\Crm\Controller\Activity;

use Bitrix\Crm\Activity\Entity;
use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Activity\Provider\ToDo\Block\Calendar;
use Bitrix\Crm\Activity\Provider\ToDo\BlocksManager;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\Activity\TodoCreateNotification;
use Bitrix\Crm\Activity\TodoPingSettingsProvider;
use Bitrix\Crm\Company;
use Bitrix\Crm\Contact;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Disk\HiddenStorage;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Multifield\Type\Phone;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\PhoneNumber\Parser;
use CCrmOwnerType;
use CIMNotify;

class ToDo extends Base
{
	private const NOTIFY_BECOME_RESPONSIBLE = 1;
	private const NOTIFY_NO_LONGER_RESPONSIBLE = 2;

	public function bindClientAction(Factory $factory, Item $entity, int $clientId, int $clientTypeId): ?array
	{
		$clientIdentifier = new ItemIdentifier($clientTypeId, $clientId);
		$clientBinder = Container::getInstance()->getClientBinder();
		$result = $clientBinder->bind($factory, $entity, $clientIdentifier);

		if ($result->isSuccess())
		{
			return $this->getClientConfigAction($entity);
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	// @todo refactor this
	public function getClientConfigAction(Item $entity): ?array
	{
		if (!Container::getInstance()->getUserPermissions()->canReadItem($entity))
		{
			$this->addError(new Error(Loc::getMessage('CRM_ACCESS_DENIED')));

			return null;
		}

		$clients = [];

		$this->addContacts($clients, $entity);
		$this->addCompanies($clients, $entity);
		$this->addCompany($clients, $entity);

		return [
			'clients' => $clients,
		];
	}

	private function addContacts(array &$clients, Item $entity): void
	{
		if (!$entity->hasField(Item::FIELD_NAME_CONTACT_BINDINGS))
		{
			return;
		}

		$contacts = $entity->getContacts();
		foreach ($contacts as $contact)
		{
			$clientEntityTypeId = \CCrmOwnerType::Contact;
			$clientEntityId = $contact->getId();
			$canReadClient = Container::getInstance()->getUserPermissions()->checkReadPermissions($clientEntityTypeId, $clientEntityId);

			if (!$canReadClient)
			{
				continue;
			}

			$this->appendClientPhones($clients, \CCrmOwnerType::Contact, $contact);
		}
	}

	private function addCompanies(array &$clients, Item $entity): void
	{
		if (
			$entity->getEntityTypeId() !== \CCrmOwnerType::Contact
			|| !$entity->hasField(Item\Contact::FIELD_NAME_COMPANY_BINDINGS)
		)
		{
			return;
		}

		/** @var Item\Contact $entity */
		$companies = $entity->getCompanies();
		foreach ($companies as $company)
		{
			$clientEntityTypeId = \CCrmOwnerType::Company;
			$clientEntityId = $company->getId();
			$canReadClient = Container::getInstance()->getUserPermissions()->checkReadPermissions($clientEntityTypeId, $clientEntityId);

			if (!$canReadClient)
			{
				continue;
			}

			$this->appendClientPhones($clients, \CCrmOwnerType::Company, $company);
		}
	}

	private function addCompany(&$clients, Item $entity): void
	{
		if (!$entity->hasField(Item::FIELD_NAME_COMPANY))
		{
			return;
		}

		$companyId = $entity->getCompanyId();
		if ($companyId)
		{
			$clientEntityTypeId = \CCrmOwnerType::Company;
			$clientEntityId = $companyId;
			$canReadCompany = Container::getInstance()
				->getUserPermissions()
				->checkReadPermissions($clientEntityTypeId, $clientEntityId)
			;

			if ($canReadCompany)
			{
				$clientTypeName = \CCrmOwnerType::ResolveName($clientEntityTypeId);

				$company = (new \Bitrix\Crm\Service\Broker\Company)->getById($companyId);
				if ($company)
				{
					$this->appendClientPhones($clients, \CCrmOwnerType::Company, $company);
				}
			}
		}
	}

	private function appendClientPhones(
		array &$clients,
		int $entityTypeId,
		Contact | Company $client
	): void
	{
		$clientId = $client->getId();
		$clientTypeName = \CCrmOwnerType::ResolveName($entityTypeId);
		$clientInfo = \CCrmEntitySelectorHelper::PrepareEntityInfo($clientTypeName, $clientId);

		$communication = [
			'customData' => [
				'entityId' => $clientId,
				'entityTypeId' => $entityTypeId,
			],
			'id' => $entityTypeId . '-' . $clientId,
			'title' => $client->getHeading(),
		];

		if (isset($clientInfo['ADVANCED_INFO']['MULTI_FIELDS']))
		{
			$phones = [];
			foreach ($clientInfo['ADVANCED_INFO']['MULTI_FIELDS'] as $mf)
			{
				if ($mf['TYPE_ID'] !== Phone::ID)
				{
					continue;
				}

				$phones[] = Parser::getInstance()->parse($mf['VALUE'])->format();
			}

			$communication['subtitle'] = implode(', ', $phones);
		}

		$clients[] = $communication;
	}

	public function getNearestAction(int $ownerTypeId, int $ownerId): ?array
	{
		$itemIdentifier = new ItemIdentifier($ownerTypeId, $ownerId);

		$todo = Entity\ToDo::loadNearest($itemIdentifier);
		if (!$todo)
		{
			return null;
		}

		return [
			'id' => $todo->getId(),
			'parentActivityId' => $todo->getParentActivityId(),
			'description' => $todo->getDescription(),
			'deadline' => $todo->getDeadline()->toString(),
			'storageElementIds' => array_map(
				'intval',
				(new HiddenStorage())->fetchFileIdsByStorageFileIds($todo->getStorageElementIds())
			),
		];
   }

	public function addAction(
		int $ownerTypeId,
		int $ownerId,
		string $deadline,
		string $title = '',
		string $description = '',
		?int $responsibleId = null,
		?int $parentActivityId = null,
		array $fileTokens = [],
		array $settings = [],
		array $pingOffsets = [],
		?string $colorId = null,
		bool $isCopy = false,
	): ?array
	{
		$todo = new Entity\ToDo(
			new ItemIdentifier($ownerTypeId, $ownerId)
		);

		$todo = $this->getPreparedEntity(
			$todo,
			$title,
			$description,
			$deadline,
			$parentActivityId,
			$responsibleId,
			$pingOffsets,
			$colorId,
			$isCopy,
		);
		if (!$todo)
		{
			return null;
		}

		if (!empty($fileTokens))
		{
			// if success save - add files
			$storageElementIds = $this->saveFilesToStorage($ownerTypeId, $ownerId, $fileTokens);
			if (!empty($storageElementIds))
			{
				$todo->setStorageElementIds($storageElementIds);
			}
		}

		$blocksManager = BlocksManager::createFromEntity($todo);
		$saveConfig = $blocksManager->preEnrichEntity($settings);
		$options = $blocksManager->getEntityOptions($settings);

		if ($saveConfig->isNeedSave())
		{
			$result = $this->saveTodo($todo, $options);
			if ($result === null)
			{
				return null;
			}
		}

		$todo = $blocksManager->enrichEntityWithBlocks($settings, false, false);

		$result = $this->saveTodo($todo, $options);
		if ($result === null)
		{
			return null;
		}

		$currentUserId = Container::getInstance()->getContext()->getUserId();
		if (isset($responsibleId) && $responsibleId !== $currentUserId)
		{
			$this->notifyViaIm(
				$result['id'],
				$ownerTypeId,
				$ownerId,
				$responsibleId,
				$currentUserId,
				self::NOTIFY_BECOME_RESPONSIBLE
			);
		}

		return $result;
	}

	public function updateAction(
		int $ownerTypeId,
		int $ownerId,
		string $deadline,
		int $id = null,
		string $title = '',
		string $description = '',
		?int $responsibleId = null,
		?int $parentActivityId = null,
		array $fileTokens = [],
		array $settings = [],
		array $pingOffsets = [],
		?string $colorId = null,
	): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		if ($todo->isCompleted())
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_UPDATE_FILES_ERROR')));

			return null;
		}

		$prevResponsibleId = $todo->getResponsibleId();

		$todo = $this->getPreparedEntity(
			$todo,
			$title,
			$description,
			$deadline,
			$parentActivityId,
			$responsibleId,
			$pingOffsets,
			$colorId,
		);
		if (!$todo)
		{
			return null;
		}

		$currentStorageElementIds = $todo->getStorageElementIds() ?? [];
		if (
			!empty($fileTokens)
			|| (!Crm::isTimelineToDoUseV2Enabled() && !empty($currentStorageElementIds)))
		{
			$storageElementIds = $this->saveFilesToStorage(
				$ownerTypeId,
				$ownerId,
				$fileTokens,
				$id,
				$currentStorageElementIds
			);

			$todo->setStorageElementIds($storageElementIds);
		}

		$blocksManager = BlocksManager::createFromEntity($todo);
		$todo = $blocksManager->enrichEntityWithBlocks($settings);
		$options = $blocksManager->getEntityOptions($settings);

		$result = $this->saveTodo($todo, $options);
		if ($result === null)
		{
			return null;
		}

		$this->tryNotifyWhenUpdate(
			$id,
			$ownerTypeId,
			$ownerId,
			$responsibleId,
			$prevResponsibleId
		);

		return $result;
	}

	protected function getPreparedEntity(
		Entity\ToDo $todo,
		string $title,
		string $description,
		string $deadline,
		?int $parentActivityId,
		?int $responsibleId = null,
		?array $pingOffsets = null,
		?string $colorId = null,
		bool $isCopy = false,
	): ?Entity\ToDo
	{
		$todo
			->setSubject($title)
			->setDescription($description)
		;

		$deadline = $this->prepareDatetime($deadline);
		if (!$deadline)
		{
			return null;
		}
		$todo
			->setDeadline($deadline)
			->setParentActivityId($parentActivityId)
		;

		if ($responsibleId)
		{
			$todo->setResponsibleId($responsibleId);
		}

		if (isset($pingOffsets))
		{
			$pingOffsets = TodoPingSettingsProvider::filterOffsets($pingOffsets);
			$todo->appendAdditionalFields(['PING_OFFSETS' => $pingOffsets]);
		}

		if (isset($colorId))
		{
			$isAvailableColorId = (new ColorSettingsProvider())->isAvailableColorId($colorId);
			if ($isAvailableColorId)
			{
				$todo->setColorId($colorId);
			}
		}

		$todo->appendAdditionalFields(['IS_COPY' => $isCopy]);

		return $todo;
	}

	public function updateDeadlineAction(
		int $ownerTypeId,
		int $ownerId,
		int $id,
		string $value
	): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		$deadline = $this->prepareDatetime($value);
		if (!$deadline)
		{
			return null;
		}

		if ($todo->getCalendarEventId() > 0)
		{
			$blocksManager = (BlocksManager::createFromEntity($todo));
			$blocks = $blocksManager->fetchAsPlainArray();
			foreach ($blocks as &$block)
			{
				if ($block['id'] === Calendar::TYPE_NAME)
				{
					$block['from'] = $deadline->getTimestamp() * 1000;
					$block['to'] = $deadline->getTimestamp() * 1000 + $block['duration'];
				}
			}
			unset($block);

			$todo = $blocksManager->enrichEntityWithBlocks($blocks, true);
		}
		else
		{
			$todo->setDeadline($deadline);
		}

		return $this->saveTodo($todo, [], true);
	}

	public function updateDescriptionAction(
		int $ownerTypeId,
		int $ownerId,
		int $id,
		string $value
	): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		$todo->setDescription($value);

		$todo = (BlocksManager::createFromEntity($todo))->enrichEntityWithBlocks(null, true);

		return $this->saveTodo($todo, [], true);
	}

	public function updateFilesAction(
		int $ownerTypeId,
		int $ownerId,
		int $id,
		array $fileTokens = []
	): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		if ($todo->isCompleted())
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_UPDATE_FILES_ERROR')));

			return null;
		}

		$todo->setStorageElementIds(
			$this->saveFilesToStorage(
				$ownerTypeId,
				$ownerId,
				$fileTokens,
				$id,
				$todo->getStorageElementIds() ?? []
			)
		);

		$todo = (BlocksManager::createFromEntity($todo))->enrichEntityWithBlocks(null, true);

		return $this->saveTodo($todo, [], true);
	}

	public function updateResponsibleUserAction(
		int $ownerTypeId,
		int $ownerId,
		int $id,
		int $responsibleId
	): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		if ($todo->isCompleted())
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_UPDATE_RESPONSIBLE_USER_ERROR')));

			return null;
		}

		if ($responsibleId <= 0)
		{
			$this->addError(new Error('Parameter "responsibleId" must be greater than 0'));

			return null;
		}

		$prevResponsibleId = $todo->getResponsibleId();
		$todo->setResponsibleId($responsibleId);

		if ($todo->getCalendarEventId() > 0)
		{
			$settings = $todo->getSettings();
			$users = $settings['USERS'] ?? [];
			if (!in_array((string)$responsibleId, $users, true))
			{
				$settings['USERS'][] = (string)$responsibleId;
				$todo->setSettings($settings);
			}

			$blocksManager = (BlocksManager::createFromEntity($todo));
			$blocks = $blocksManager->fetchAsPlainArray();
			foreach ($blocks as &$block)
			{
				if ($block['id'] === Calendar::TYPE_NAME)
				{
					$block['selectedUserIds'] = $settings['USERS'];
				}
			}
			unset($block);

			$todo = $blocksManager->enrichEntityWithBlocks($blocks, true);
		}

		$result = $this->saveTodo($todo, [], true);
		if ($result === null)
		{
			return null;
		}

		$this->tryNotifyWhenUpdate(
			$id,
			$ownerTypeId,
			$ownerId,
			$responsibleId,
			$prevResponsibleId
		);

		return $result;
	}

	public function updatePingOffsetsAction(int $ownerTypeId, int $ownerId, int $id, array $value = []): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		if ($todo->isCompleted())
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_UPDATE_PING_OFFSETS_ERROR')));

			return null;
		}

		$filteredValue = TodoPingSettingsProvider::filterOffsets($value);
		if (!empty($value) && empty($filteredValue))
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_WRONG_PING_OFFSETS_FORMAT')));

			return null; // nothing to do - wrong input
		}

		$todo->setAdditionalFields(['PING_OFFSETS' => $filteredValue]);

		$todo = (BlocksManager::createFromEntity($todo))->enrichEntityWithBlocks(null, true);

		return $this->saveTodo($todo, [], true);
	}

	public function updateColorAction(int $ownerTypeId, int $ownerId, int $id, string $colorId): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);
		if (!$todo)
		{
			return null;
		}

		if ($todo->isCompleted())
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_UPDATE_COLOR_ERROR')));

			return null;
		}

		if (!(new ColorSettingsProvider())->isAvailableColorId($colorId))
		{
			$this->addError(new Error( Loc::getMessage('CRM_ACTIVITY_TODO_WRONG_COLOR')));

			return null;
		}

		$todo->setColorId($colorId);

		$todo = (BlocksManager::createFromEntity($todo))->enrichEntityWithBlocks(null, true);

		return $this->saveTodo($todo, [], true);
	}

	protected function loadEntity(int $ownerTypeId, int $ownerId, int $id): ?Entity\ToDo
	{
		$itemIdentifier = new ItemIdentifier($ownerTypeId, $ownerId);
		$todo = Entity\ToDo::load($itemIdentifier, $id);

		if (!$todo)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		return $todo;
	}

	public function skipEntityDetailsNotificationAction(int $entityTypeId, string $period): bool
	{
		if (!CCrmOwnerType::ResolveName($entityTypeId))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($entityTypeId));
		}

		$result = (new TodoCreateNotification($entityTypeId))->skipForPeriod($period);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result->isSuccess();
	}

	private function saveTodo(Entity\ToDo $todo, array $options = [], bool $useCurrentSettings = false): ?array
	{
		$saveResult = $todo->save($options, $useCurrentSettings);
		if ($saveResult->isSuccess())
		{
			return [
				'id' => $todo->getId(),
			];
		}

		$this->addErrors($saveResult->getErrors());

		return null;
	}

	private function saveFilesToStorage(
		int $ownerTypeId,
		int $ownerId,
		array $fileUploaderIds,
		?int $activityId = null,
		array $currentStorageElementIds = []
	): array
	{
		$fileUploader = new Provider\ToDo\FileUploader\Uploader(
			$fileUploaderIds,
			$ownerTypeId,
			$ownerId
		);

		$result = $fileUploader
			->setActivityId($activityId)
			->setCurrentStorageElementIds($currentStorageElementIds)
			->saveFilesToStorage()
		;

		if ($result->isSuccess())
		{
			return $result->getData()['ids'];
		}

		$this->addErrors($result->getErrors());

		return [];
	}

	public function fetchSettingsAction(int $ownerTypeId, int $ownerId, int $id): ?array
	{
		$todo = $this->loadEntity($ownerTypeId, $ownerId, $id);

		if (!$todo)
		{
			return null;
		}

		$itemIdentifier = new ItemIdentifier($ownerTypeId, $ownerId);
		$currentFileIds = $this->getCurrentFileIds($itemIdentifier, $todo->getStorageElementIds());

 		return [
			 'entityData' => [
				'id' => $todo->getId(),
				'title' => $todo->getSubject(),
				'description' => $todo->getDescription(),
				'deadline' => $todo->getDeadline(),
				'currentFileIds' => array_values($currentFileIds),
				'colorId' => $todo->getSettings()['COLOR'] ?? null,
				'currentUser' => $this->getTodoCurrentUser($todo->getResponsibleId()),
				'pingOffsets' => $this->getPingOffsets($todo),
			 ],
			 'blocksData' => BlocksManager::createFromEntity($todo)->fetch(),
		];
	}

	private function getPingOffsets(Entity\ToDo $todo): array
	{
		$offsets = (array)($todo->getSettings()['PING_OFFSETS'] ?? []);
		if (empty($offsets))
		{
			$offsets = Provider\ToDo\ToDo::getPingOffsets($todo->getId());
		}

		return $offsets;
	}

	public function getTodoCurrentUser(?int $userId): array
	{
		$currentUser = Container::getInstance()
			->getUserBroker()
			->getById($userId ?? CurrentUser::get()->getId());

		return [
			'userId' => $currentUser['ID'] ?? 0,
			'title' => $currentUser['FORMATTED_NAME'] ?? '',
			'detailUrl' => $currentUser['SHOW_URL'] ?? '',
			'imageUrl' => $currentUser['PHOTO_URL'] ?? '',
		];
	}

	// @todo remove/refactor after move files to block
	protected function getCurrentFileIds(ItemIdentifier $item, array $storageFileIds): array
	{
		return $this->getHiddenStorage($item)->fetchFileIdsByStorageFileIds(
			$storageFileIds,
			HiddenStorage::USE_DISK_OBJ_ID_AS_KEY
		);
	}

	// @todo remove/refactor after move files to block
	protected function getHiddenStorage(ItemIdentifier $item): HiddenStorage
	{
		return (new HiddenStorage())->setSecurityContextOptions([
			'entityTypeId' => $item->getEntityTypeId(),
			'entityId' => $item->getEntityId(),
		]);
	}

	private function notifyViaIm(int $activityId, int $ownerTypeId, int $ownerId, int $responsibleId, int $authorId, int $options = 0): void
	{
		if ($options === 0 || !Loader::includeModule('im'))
		{
			return;
		}

		$todo = $this->loadEntity($ownerTypeId, $ownerId, $activityId);
		if (!$todo)
		{
			return;
		}

		[$message, $messageOut] = $this->createNotificationMessages(
			$activityId,
			$ownerTypeId,
			$ownerId,
			trim($todo->getSubject()),
			$options
		);
		if (!isset($message, $messageOut))
		{
			return;
		}

		CIMNotify::Add([
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'crm',
			'TO_USER_ID' =>$responsibleId,
			'FROM_USER_ID' => $authorId,
			'NOTIFY_EVENT' => 'changeAssignedBy',
			'NOTIFY_TAG' => 'CRM|TODO_ACTIVITY|' . $activityId,
			"NOTIFY_MESSAGE" => $message,
			"NOTIFY_MESSAGE_OUT" => $messageOut,
		]);
	}

	private function tryNotifyWhenUpdate(int $activityId, int $ownerTypeId, int $ownerId, int $responsibleId, int $prevResponsibleId): void
	{
		if ($responsibleId === $prevResponsibleId)
		{
			return;
		}

		$currentUserId = Container::getInstance()->getContext()->getUserId();

		$this->notifyViaIm(
			$activityId,
			$ownerTypeId,
			$ownerId,
			$prevResponsibleId,
			$currentUserId,
			self::NOTIFY_NO_LONGER_RESPONSIBLE
		);

		$this->notifyViaIm(
			$activityId,
			$ownerTypeId,
			$ownerId,
			$responsibleId,
			$currentUserId,
			self::NOTIFY_BECOME_RESPONSIBLE
		);
	}

	private function createNotificationMessages(int $activityId, int $ownerTypeId, int $ownerId, string $subject, int $options): array
	{
		$url = Container::getInstance()->getRouter()->getItemDetailUrl($ownerTypeId, $ownerId);
		if (!isset($url))
		{
			return [];
		}

		// get phase code by input parameters
		$phaseCodeSuffix = '';
		if ($options & self::NOTIFY_BECOME_RESPONSIBLE)
		{
			$phaseCodeSuffix = 'BECOME';
		}
		elseif ($options & self::NOTIFY_NO_LONGER_RESPONSIBLE)
		{
			$phaseCodeSuffix = 'NO_LONGER';
		}

		if ($subject === '')
		{
			// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE
			// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE
			return [
				Loc::getMessage('CRM_ACTIVITY_TODO_' . $phaseCodeSuffix . '_RESPONSIBLE', [
					'#todoId#' =>  '<a href="' . $url . '">' . $activityId . '</a>'
				]),
				Loc::getMessage('CRM_ACTIVITY_TODO_' . $phaseCodeSuffix . '_RESPONSIBLE', [
					'#todoId#' => $activityId
				])
			];
		}

		$entityName = '';
		if (CCrmOwnerType::isUseFactoryBasedApproach($ownerTypeId))
		{
			$factory = Container::getInstance()->getFactory($ownerTypeId);
			if ($factory)
			{
				$item = $factory->getItem($ownerId);
				if ($item)
				{
					$entityName = trim($item->getHeading());
				}
			}
		}

		if ($entityName === '')
		{
			// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_EX
			// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_EX
			return [
				Loc::getMessage('CRM_ACTIVITY_TODO_' . $phaseCodeSuffix . '_RESPONSIBLE_EX', [
					'#subject#' =>  '<a href="' . $url . '">' . htmlspecialcharsbx($subject) . '</a>'
				]),
				Loc::getMessage('CRM_ACTIVITY_TODO_' . $phaseCodeSuffix . '_RESPONSIBLE_EX', [
					'#subject#' => htmlspecialcharsbx($subject)
				])
			];
		}

		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_LEAD
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_DEAL
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_CONTACT
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_COMPANY
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_QUOTE_MSGVER_1
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_ORDER
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_SMART_INVOICE
		// CRM_ACTIVITY_TODO_BECOME_RESPONSIBLE_DYNAMIC
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_LEAD
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_DEAL
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_CONTACT
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_COMPANY
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_QUOTE_MSGVER_1
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_ORDER
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_SMART_INVOICE
		// CRM_ACTIVITY_TODO_NO_LONGER_RESPONSIBLE_DYNAMIC

		$phaseCodeEnd = CCrmOwnerType::isPossibleDynamicTypeId($ownerTypeId)
			? CCrmOwnerType::CommonDynamicName
			: CCrmOwnerType::ResolveName($ownerTypeId);

		if ($phaseCodeEnd === 'QUOTE')
		{
			$phaseCodeEnd = 'QUOTE_MSGVER_1';
		}

		return [
			Loc::getMessage('CRM_ACTIVITY_TODO_' . $phaseCodeSuffix . '_RESPONSIBLE_' . $phaseCodeEnd, [
				'#subject#' => htmlspecialcharsbx($subject),
				'#entityName#' => '<a href="' . $url . '">' . htmlspecialcharsbx($entityName) . '</a>',
			]),
			Loc::getMessage('CRM_ACTIVITY_TODO_' . $phaseCodeSuffix . '_RESPONSIBLE_' . $phaseCodeEnd, [
				'#subject#' => htmlspecialcharsbx($subject),
				'#entityName#' => htmlspecialcharsbx($entityName),
			])
		];
	}
}
