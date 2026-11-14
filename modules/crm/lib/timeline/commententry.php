<?php

namespace Bitrix\Crm\Timeline;

use Bitrix\Crm;
use Bitrix\Crm\Integration\Disk\DiskRepository;
use Bitrix\Disk;
use Bitrix\Main;
use Bitrix\Main\Text\Emoji;

class CommentEntry extends TimelineEntry
{
	private const ON_CRM_TIMELINE_COMMENT_ADD_EVENT = "OnAfterCrmTimelineCommentAdd";
	private const ON_CRM_TIMELINE_COMMENT_UPDATE_EVENT = "OnAfterCrmTimelineCommentUpdate";
	private const ON_CRM_TIMELINE_COMMENT_DELETE_EVENT = "OnAfterCrmTimelineCommentDelete";

	public static function create(array $params)
	{
		[$authorId, $created, $settings, $bindings] = self::fetchParams($params);
		$text = isset($params['TEXT']) ? Emoji::encode($params['TEXT']) : '';
		if ($text === '')
		{
			throw new Main\ArgumentException('Text must be greater not empty string.', 'text');
		}

		self::markCallTrackerActivitiesAsCompleted($bindings);

		$result = Entity\TimelineTable::add([
			'TYPE_ID' => TimelineType::COMMENT,
			'TYPE_CATEGORY_ID' => 0,
			'CREATED' => $created,
			'AUTHOR_ID' => $authorId,
			'COMMENT' => $text,
			'SETTINGS' => $settings,
			'ASSOCIATED_ENTITY_TYPE_ID' => 0,
			'ASSOCIATED_ENTITY_ID' => 0
		]);
		if (!$result->isSuccess())
		{
			return 0;
		}

		$createdId = $result->getId();

		if (isset($params['FILES']) && is_array($params['FILES']))
		{
			self::attachFiles($createdId, $params['FILES']);
		}

		self::registerBindings($createdId, $bindings, $created);
		self::buildSearchContent($createdId);

		$event = new Main\Event("crm", self::ON_CRM_TIMELINE_COMMENT_ADD_EVENT, ['ID' => $createdId]);
		$event->send();

		return $createdId;
	}

	/** @internal */
	public static function createForSplit(array $params): Crm\Result
	{
		$result = new Crm\Result();
		$createdId = null;
		try
		{
			[$authorId, $created, $settings, $bindings] = self::fetchParams($params);
			$text = isset($params['TEXT']) ? Emoji::encode($params['TEXT']) : '';
			if ($text === '')
			{
				throw new Main\ArgumentException('Text must be greater not empty string.', 'text');
			}

			$addResult = Entity\TimelineTable::add([
				'TYPE_ID' => TimelineType::COMMENT,
				'TYPE_CATEGORY_ID' => 0,
				'CREATED' => $created,
				'AUTHOR_ID' => $authorId,
				'COMMENT' => $text,
				'SETTINGS' => $settings,
				'ASSOCIATED_ENTITY_TYPE_ID' => 0,
				'ASSOCIATED_ENTITY_ID' => 0,
			]);
			if (!$addResult->isSuccess())
			{
				return $result->addErrors($addResult->getErrors());
			}

			$createdId = (int)$addResult->getId();
			$result->setData(['id' => $createdId]);
			$files = isset($params['FILES']) && is_array($params['FILES']) ? $params['FILES'] : [];
			$filesResult = self::saveFiles($createdId, $files);
			if (!$filesResult->isSuccess())
			{
				return $result->addErrors($filesResult->getErrors());
			}

			self::registerBindings($createdId, $bindings, $created);
			self::buildSearchContent($createdId);

			$event = new Main\Event('crm', self::ON_CRM_TIMELINE_COMMENT_ADD_EVENT, ['ID' => $createdId]);
			$event->send();
		}
		catch (\Throwable $exception)
		{
			Crm\Service\Container::getInstance()->getLogger('Default')->error(
				'{method}: unable to create split comment {commentId}',
				[
					'method' => __METHOD__,
					'commentId' => $createdId,
					'exceptionClass' => get_class($exception),
					'exceptionCode' => $exception->getCode(),
					'exceptionFile' => $exception->getFile(),
					'exceptionLine' => $exception->getLine(),
				],
			);

			$result->addError(new Main\Error('Could not create comment'));
		}

		return $result;
	}

	/**
	 * @param $ID
	 * @param array $attachment
	 */
	private static function attachFiles($ID, array $attachment)
	{
		global $USER_FIELD_MANAGER;
		$ID = (int)$ID;
		if($ID <= 0)
		{
			return;
		}

		$ufValues = [
			CommentController::UF_COMMENT_FILE_NAME => $attachment,
		];
		if ($USER_FIELD_MANAGER->CheckFields(CommentController::UF_FIELD_NAME, $ID, $ufValues))
		{
			$USER_FIELD_MANAGER->Update(CommentController::UF_FIELD_NAME, $ID, $ufValues);
		}
	}

	/** @internal */
	public static function validateFiles(int $id, array $files): Crm\Result
	{
		global $USER_FIELD_MANAGER, $APPLICATION;

		if ($USER_FIELD_MANAGER->CheckFields(CommentController::UF_FIELD_NAME, $id, [
			CommentController::UF_COMMENT_FILE_NAME => $files,
		]) === false)
		{
			$result = Crm\Result::failFromApplication();
			$APPLICATION->ResetException();

			return $result;
		}

		return new Crm\Result();
	}

	private static function saveFiles(int $id, array $files): Crm\Result
	{
		global $USER_FIELD_MANAGER;

		$result = self::validateFiles($id, $files);
		if (!$result->isSuccess())
		{
			return $result;
		}
		if (!Main\Loader::includeModule('disk'))
		{
			// without disk there are no attachments to save; the controller always passes a full list
			return $files === [] ? $result : Crm\Result::failModuleNotInstalled('disk');
		}

		$files = self::normalizeFiles($files);
		$before = self::getRawAttachedIds($id);
		if ($USER_FIELD_MANAGER->Update(CommentController::UF_FIELD_NAME, $id, [
			CommentController::UF_COMMENT_FILE_NAME => $files,
		]) === false)
		{
			return Crm\Result::fail('Could not save comment attachments');
		}

		$saved = self::getRawAttachedIds($id);
		$objects = Disk\Driver::getInstance()->getUserFieldManager()->getAttachedObjectByEntity(
			CommentController::UF_FIELD_NAME,
			$id,
			CommentController::UF_COMMENT_FILE_NAME,
		);
		$attachedIdsByFile = [];
		foreach ($objects as $attachedId => $object)
		{
			$attachedIdsByFile[(int)$object->getObjectId()][] = $attachedId;
		}
		$nextAttachedIdByFile = [];
		$unmatched = $objects;
		foreach (array_unique($files) as $value)
		{
			if (is_numeric($value))
			{
				if (!isset($unmatched[(int)$value]))
				{
					return Crm\Result::fail('Comment attachment set mismatch');
				}
				unset($unmatched[(int)$value]);
				continue;
			}

			$fileId = (int)substr($value, strlen(Disk\Uf\FileUserType::NEW_FILE_PREFIX));
			$nextAttachedIdByFile[$fileId] ??= 0;
			while (isset($attachedIdsByFile[$fileId][$nextAttachedIdByFile[$fileId]]))
			{
				$attachedId = $attachedIdsByFile[$fileId][$nextAttachedIdByFile[$fileId]++];
				if (isset($unmatched[$attachedId]))
				{
					unset($unmatched[$attachedId]);
					continue 2;
				}
			}

			return Crm\Result::fail('Comment attachment set mismatch');
		}
		if (
			$unmatched !== []
			|| array_diff($saved, array_keys($objects)) !== []
			|| array_diff(array_keys($objects), $saved) !== []
		)
		{
			return Crm\Result::fail('Comment attachment set mismatch');
		}

		$removed = array_values(array_diff($before, $saved));
		if ($removed !== [])
		{
			DiskRepository::getInstance()->detachByAttachedObjectIds($removed);
			foreach ($removed as $attachedId)
			{
				if (Disk\AttachedObject::getById($attachedId) !== null)
				{
					return Crm\Result::fail('Could not detach comment attachment');
				}
			}
		}

		$row = Entity\TimelineTable::getRowById($id);
		$settings = $row['SETTINGS'] ?? [];
		$hasFiles = $saved === [] ? 'N' : 'Y';
		if (($settings['HAS_FILES'] ?? null) !== $hasFiles)
		{
			$settings['HAS_FILES'] = $hasFiles;
			$settingsResult = Entity\TimelineTable::update($id, ['SETTINGS' => $settings]);
			if (!$settingsResult->isSuccess())
			{
				return $result->addErrors($settingsResult->getErrors());
			}
		}
		$row = Entity\TimelineTable::getRowById($id);
		if (($row['SETTINGS']['HAS_FILES'] ?? null) !== $hasFiles)
		{
			return Crm\Result::fail('Comment attachment settings mismatch');
		}

		return $result;
	}

	// Disk attaches every value separately: a repeated n<ID> or the n<ID> of a file already listed
	// by its attached object would create a second attachment and break the saved set check.
	private static function normalizeFiles(array $files): array
	{
		$files = array_values(array_unique($files));
		$attachedFileIds = [];
		foreach ($files as $value)
		{
			if (is_numeric($value))
			{
				$object = Disk\Driver::getInstance()->getUserFieldManager()->getAttachedObjectById((int)$value);
				if ($object)
				{
					$attachedFileIds[(int)$object->getObjectId()] = true;
				}
			}
		}

		return array_values(array_filter($files, static function ($value) use ($attachedFileIds): bool {
			if (is_numeric($value))
			{
				return true;
			}
			$fileId = (int)substr((string)$value, strlen(Disk\Uf\FileUserType::NEW_FILE_PREFIX));

			return !isset($attachedFileIds[$fileId]);
		}));
	}

	private static function getRawAttachedIds(int $id): array
	{
		global $USER_FIELD_MANAGER;

		$fields = $USER_FIELD_MANAGER->GetUserFields(CommentController::UF_FIELD_NAME, $id);
		$values = $fields[CommentController::UF_COMMENT_FILE_NAME]['VALUE'] ?? [];
		$values = is_array($values) ? $values : (($values === null || $values === '') ? [] : [$values]);
		Main\Type\Collection::normalizeArrayValuesByInt($values);

		return $values;
	}

	public static function rebind($entityTypeID, $oldEntityID, $newEntityID)
	{
		Entity\TimelineBindingTable::rebind($entityTypeID, $oldEntityID, $newEntityID, array(TimelineType::COMMENT));
	}

	public static function attach($srcEntityTypeID, $srcEntityID, $targEntityTypeID, $targEntityID)
	{
		Entity\TimelineBindingTable::attach($srcEntityTypeID, $srcEntityID, $targEntityTypeID, $targEntityID, array(TimelineType::COMMENT));
	}

	public static function update($ID, array $params)
	{
		$result = new Main\ORM\Data\UpdateResult();
		$result->setPrimary(['ID' => $ID]);

		if ($ID <= 0)
		{
			$result->addError(new Main\Error('Wrong entity ID'));
			return $result;
		}

		$hasFiles = isset($params['FILES']) && is_array($params['FILES']);
		if ($hasFiles)
		{
			$validation = self::validateFiles((int)$ID, $params['FILES']);
			if (!$validation->isSuccess())
			{
				return $validation;
			}
		}

		$updateData = array();

		if (isset($params['COMMENT']))
			$updateData['COMMENT'] = \Bitrix\Main\Text\Emoji::encode($params['COMMENT']);

		if (isset($params['SETTINGS']) && is_array($params['SETTINGS']))
			$updateData['SETTINGS'] = $params['SETTINGS'];

		if (!empty($updateData))
		{
			$result = Entity\TimelineTable::update($ID, $updateData);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		if ($hasFiles)
		{
			$filesResult = self::saveFiles((int)$ID, $params['FILES']);
			if (!$filesResult->isSuccess())
			{
				return $filesResult;
			}
		}

		self::buildSearchContent($ID);

		$event = new Main\Event("crm", self::ON_CRM_TIMELINE_COMMENT_UPDATE_EVENT, ['ID' => $ID]);
		$event->send();

		return $result;
	}

	public static function delete($ID)
	{
		$result = parent::delete($ID);

		$GLOBALS['USER_FIELD_MANAGER']->Delete(CommentController::UF_FIELD_NAME, $ID);

		$event = new Main\Event("crm", self::ON_CRM_TIMELINE_COMMENT_DELETE_EVENT, ['ID' => $ID]);
		$event->send();

		return $result;
	}

	private static function markCallTrackerActivitiesAsCompleted(array $bindings): void
	{
		foreach ($bindings as $binding)
		{
			$ownerTypeId = (int)$binding['ENTITY_TYPE_ID'];
			$ownerId = (int)$binding['ENTITY_ID'];

			if ($ownerTypeId <= 0 || $ownerId <= 0)
			{
				continue;
			}

			$activityCollection = \CCrmActivity::GetList(
				[
					'ID' => 'ASC',
				],
				[
					'TYPE_ID' => \CCrmActivityType::Provider,
					'PROVIDER_ID' => \Bitrix\Crm\Activity\Provider\CallTracker::PROVIDER_ID,
					'OWNER_TYPE_ID' => $ownerTypeId,
					'OWNER_ID' => $ownerId,
					'CHECK_PERMISSIONS' => 'Y',
					'COMPLETED' => 'N',
				],
				false,
				false,
				['ID']
			);

			while ($activity = $activityCollection->Fetch())
			{
				\CCrmActivity::Complete(
					$activity['ID'],
					true,
					[
						'REGISTER_SONET_EVENT' => true,
					]
				);
			}
		}
	}
}
