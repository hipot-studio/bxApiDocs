<?php

namespace Bitrix\Disk;

use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk;
use Bitrix\Disk\Realtime\Events\UserRecentsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;

final class TrackedObjectManager
{
	protected ErrorCollection $errorCollection;
	protected array $dataToInsert = [];
	private ?JournalWriter $journalWriter = null;

	/**
	 * Constructor RecentlyUsedManager.
	 */
	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
		Application::getInstance()->addBackgroundJob([$this, 'finalize']);
	}

	public function finalize(): void
	{
		$rightsManager = Driver::getInstance()->getRightsManager();
		foreach ($this->dataToInsert as $item)
		{
			[
				'userId' => $userId,
				'object' => $object,
				'attachedObject' => $attachedObject,
				'canRead' => $canRead,
			] = $item;

			if (!($object instanceof File))
			{
				continue;
			}

			if ($canRead || $rightsManager->hasSimpleRight($userId, $object->getId()))
			{
				$this->processPush($userId, $object, $attachedObject);
			}
		}
	}

	/**
	 * Push in recently used new object.
	 * @param mixed|int|User|\CUser $user User.
	 * @param Disk\File $object Object id.
	 * @return void
	 */
	public function pushFile($user, File $object, bool $canRead = null): void
	{
		$userId = User::resolveUserId($user);
		if ($userId === null)
		{
			return;
		}

		$userIds = [(int)$userId];

		//experiemental logic to add each file in collab group to every member without any interaction
		$collabService = new Disk\Integration\Collab\CollabService();
		$collab = $collabService->getCollabByStorage($object->getStorage());
		if ($collab)
		{
			foreach ($collab->getUserMemberIds() as $memberId)
			{
				if ($collabService->isCollaberUserById($memberId))
				{
					$userIds[] = (int)$memberId;
				}
			}
		}

		$userIds = array_unique($userIds);
		foreach ($userIds as $userId)
		{
			$this->dataToInsert[] = [
				'userId' => $userId,
				'object' => $object,
				'attachedObject' => null,
				'canRead' => $canRead,
			];
		}
	}

	public function pushAttachedObject($user, AttachedObject $attachedObject, bool $canRead = null): void
	{
		$userId = User::resolveUserId($user);
		if ($userId === null)
		{
			return;
		}

		$this->dataToInsert[] = [
			'userId' => (int)$userId,
			'object' => $attachedObject->getFile(),
			'attachedObject' => $attachedObject,
			'canRead' => $canRead,
		];
	}

	protected function processPush($user, File $object, AttachedObject $attachedObject = null): bool
	{
		$userId = User::resolveUserId($user);
		if (!$userId)
		{
			return false;
		}

		$alreadyExists = Disk\Internals\TrackedObjectTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('REAL_OBJECT_ID', $object->getRealObjectId())
			->fetch()
		;

		if ($alreadyExists && !empty($alreadyExists['ID']))
		{
			$this->refresh($object, $userId);
		}
		else
		{
			$addResult = null;
			try
			{
				$addResult = Disk\Internals\TrackedObjectTable::add([
					'USER_ID' => $userId,
					'OBJECT_ID' => $object->getId(),
					'REAL_OBJECT_ID' => $object->getRealObjectId(),
					'ATTACHED_OBJECT_ID' => $attachedObject?->getId(),
					'TYPE_FILE' => $object->getTypeFile(),
				]);
			}
			catch (DuplicateEntryException)
			{
			}

			if ($addResult?->isSuccess())
			{
				// The entry into the set is published from the point that wrote the row, and only for a
				// first entry: a duplicate means the user already held the object, and the writer takes
				// "first" on trust from here.
				$this->getJournalWriter()->publishInteractionEntered($object->getRealObjectId(), $userId);
			}

			$event = new UserRecentsEvent(
				$userId,
				'newRecentsFile',
				[
					'file' => [
						'id' => (int)$object->getId(),
						'realObjectId' => (int)$object->getRealObjectId(),
						'storageId' => (int)$object->getStorageId(),
					],
					'attachedObject' => [
						'id' => $attachedObject?->getId(),
						'TYPE_FILE' => $object->getTypeFile(),
					],
				],
			);
			$event->sendToUser();
		}

		return true;
	}

	private function getJournalWriter(): JournalWriter
	{
		return $this->journalWriter ??= new JournalWriter();
	}

	/**
	 * Refreshes the freshness stamp of the object in the set of one user.
	 *
	 * The stamp is personal - it is the date this user reached the object, and it is read as such by the
	 * page of recent documents and by the sync protocol. An interaction of one user therefore moves the
	 * stamp of that user alone.
	 *
	 * @param mixed|int|User|\CUser $user User of the interaction; the current one when not given.
	 */
	public function refresh(BaseObject $object, $user = null): void
	{
		$userId = User::resolveUserId($user ?? CurrentUser::get());
		if ($userId === null)
		{
			return;
		}

		Disk\Internals\TrackedObjectTable::updateBatch(
			['UPDATE_TIME' => new DateTime()],
			[
				'USER_ID' => $userId,
				'REAL_OBJECT_ID' => $object->getRealObjectId(),
			]
		);
	}
}