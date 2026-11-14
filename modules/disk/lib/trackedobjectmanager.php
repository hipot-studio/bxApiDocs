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
	private bool $finalizeScheduled = false;
	private bool $finalizing = false;
	private array $processedBeforeFailure = [];

	/**
	 * Constructor RecentlyUsedManager.
	 */
	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
		$this->scheduleFinalize();
	}

	public function finalize(): void
	{
		if ($this->finalizing)
		{
			return;
		}
		$this->finalizeScheduled = false;
		$this->finalizing = true;
		$rightsManager = Driver::getInstance()->getRightsManager();
		$collabUsersByStorage = [];
		$processed = $this->processedBeforeFailure;
		$this->processedBeforeFailure = [];
		$items = [];
		$nextItem = 0;
		try
		{
			// A write callback may add another intention; the same job consumes it without scheduling itself.
			while ($this->dataToInsert !== [])
			{
				$items = $this->dataToInsert;
				$this->dataToInsert = [];
				$nextItem = 0;
				foreach ($items as $index => $item)
				{
					$nextItem = $index + 1;
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
					$userIds = [$userId];
					if ($attachedObject === null)
					{
						$storageId = (int)$object->getStorageId();
						$collabUsersByStorage[$storageId] ??= $this->getCollabUserIds($object);
						$userIds = array_merge($userIds, $collabUsersByStorage[$storageId]);
					}
					foreach (array_unique($userIds) as $recipientId)
					{
						if (!$canRead && !$rightsManager->hasSimpleRight($recipientId, $object->getId()))
						{
							continue;
						}
						$key = $recipientId . ':' . $object->getId();
						$attachedId = $attachedObject?->getId();
						if (
							array_key_exists($key, $processed)
							&& ($attachedId === null || $processed[$key] === $attachedId)
						)
						{
							continue;
						}
						if ($this->processPush($recipientId, $object, $attachedObject))
						{
							// A file refresh retains the attached context; another attachment replaces it.
							$processed[$key] = $attachedId ?? ($processed[$key] ?? null);
						}
					}
				}
			}
		}
		catch (\Throwable $exception)
		{
			// The failed item may already have written data. Continue only unstarted and newly added intentions.
			$this->dataToInsert = array_merge(array_slice($items, $nextItem), $this->dataToInsert);
			if ($this->dataToInsert !== [])
			{
				$this->processedBeforeFailure = $processed;
			}
			throw $exception;
		}
		finally
		{
			$this->finalizing = false;
			if ($this->dataToInsert !== [])
			{
				$this->scheduleFinalize();
			}
		}
	}

	private function scheduleFinalize(): void
	{
		if (!$this->finalizeScheduled && !$this->finalizing)
		{
			$this->finalizeScheduled = true;
			Application::getInstance()->addBackgroundJob([$this, 'finalize']);
		}
	}

	/** Collab recipients are resolved after the response, only for unattached file intentions. */
	private function getCollabUserIds(File $object): array
	{
		$storage = $object->getStorage();
		if (!$storage instanceof Storage)
		{
			return [];
		}
		$collabService = new Disk\Integration\Collab\CollabService();
		$collab = $collabService->getCollabByStorage($storage);
		$userIds = [];
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

		return array_unique($userIds);
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

		$this->dataToInsert[] = [
			'userId' => (int)$userId,
			'object' => $object,
			'attachedObject' => null,
			'canRead' => $canRead,
		];
		$this->scheduleFinalize();
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
		$this->scheduleFinalize();
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
			if ($attachedObject === null)
			{
				$this->refresh($object, $userId);
			}
			else
			{
				Disk\Internals\TrackedObjectTable::updateBatch(
					[
						'ATTACHED_OBJECT_ID' => $attachedObject->getId(),
						'UPDATE_TIME' => new DateTime(),
					],
					[
						'USER_ID' => $userId,
						'REAL_OBJECT_ID' => $object->getRealObjectId(),
					],
				);
			}
		}
		else
		{
			$realObject = $object->getRealObject();
			$realObjectId = (int)($realObject?->getId() ?: $object->getRealObjectId());
			$markerService = new Disk\Internal\Service\UploadCleanup\MarkerService();
			if (!$markerService->revokeBeforeUse(
				$realObjectId,
				$realObject?->getUploadCleanupMarkerPresenceHint(),
			))
			{
				return false;
			}

			$addResult = null;
			try
			{
				$addResult = $markerService->runWithConfirmedRevokedMarker(
					$realObjectId,
					static fn() => Disk\Internals\TrackedObjectTable::add([
						'USER_ID' => $userId,
						'OBJECT_ID' => $object->getId(),
						'REAL_OBJECT_ID' => $realObjectId,
						'ATTACHED_OBJECT_ID' => $attachedObject?->getId(),
						'TYPE_FILE' => $object->getTypeFile(),
					]),
				);
				if (!$addResult->isSuccess())
				{
					return false;
				}
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
