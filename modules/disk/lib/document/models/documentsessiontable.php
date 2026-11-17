<?php

namespace Bitrix\Disk\Document\Models;

use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\OpenDebtCarrier;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Entity\BooleanField;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;

/**
 * Class DocumentSessionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentSession_Query query()
 * @method static EO_DocumentSession_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentSession_Result getById($id)
 * @method static EO_DocumentSession_Result getList(array $parameters = [])
 * @method static EO_DocumentSession_Entity getEntity()
 * @method static \Bitrix\Disk\Document\Models\EO_DocumentSession createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Document\Models\EO_DocumentSession_Collection createCollection()
 * @method static \Bitrix\Disk\Document\Models\EO_DocumentSession wakeUpObject($row)
 * @method static \Bitrix\Disk\Document\Models\EO_DocumentSession_Collection wakeUpCollection($rows)
 */
final class DocumentSessionTable extends DataManager
{
	public const TYPE_VIEW = 0;
	public const TYPE_EDIT = 2;

	public const STATUS_BROKEN = -1;
	public const STATUS_ACTIVE = 0;
	public const STATUS_NON_ACTIVE = 2;

	public const EXTERNAL_HASH_LENGTH = 128;

	private const DELETE_CHUNK_SIZE = 500;
	private const CONTEXT_READ_CHUNK_SIZE = 100;

	public static function onBeforeAdd(Event $event): EventResult
	{
		$result = new EventResult();
		$fields = $event->getParameter('fields');
		$objectId = (int)($fields['OBJECT_ID'] ?? 0);
		if ($objectId > 0 && !(new MarkerService())->revokeBeforeUse($objectId))
		{
			$result->addError(new EntityError(MarkerService::ERROR_FILE_IS_BEING_REMOVED));
		}

		return $result;
	}

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_disk_document_session';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('SERVICE'))
				->configureSize(50)
			,
			new IntegerField('OBJECT_ID'),
			new IntegerField('VERSION_ID'),
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new IntegerField('OWNER_ID'))
				->configureRequired()
			,
			(new BooleanField('IS_EXCLUSIVE'))
				->configureDefaultValue(0)
				->configureStorageValues(0, 1),
			(new StringField('EXTERNAL_HASH'))
				->configureRequired()
				->configureSize(self::EXTERNAL_HASH_LENGTH)
				->configureDefaultValue(function() {
					return self::generateDocumentKey();
				})
			,
			(new DatetimeField('CREATE_TIME'))
				->configureRequired()
				->configureDefaultValue(function() {
					return new DateTime();
				})
			,
			(new IntegerField('TYPE'))
				->configureDefaultValue(self::TYPE_VIEW)
			,
			(new IntegerField('STATUS'))
				->configureDefaultValue(self::STATUS_ACTIVE)
			,
			new IntegerField('EXTERNAL_LINK_ID'),
			new TextField('CONTEXT'),
		];
	}

	public static function generateDocumentKey(): string
	{
		$randomLength = self::EXTERNAL_HASH_LENGTH;
		$cloudRegistrationData = (new OnlyOffice\Configuration)->getCloudRegistrationData();
		if ($cloudRegistrationData)
		{
			$prefixLength = strlen($cloudRegistrationData['clientId']) + 1;
			$randomPart = Random::getString($randomLength - $prefixLength, true);

			return "{$cloudRegistrationData['clientId']}.{$randomPart}";
		}

		return Random::getString($randomLength, true);
	}

	public static function onAfterAdd(Event $event)
	{
		parent::onAfterAdd($event);

		$fields = $event->getParameter('fields');
		self::tryToAddInfo($fields);
	}

	public static function tryToAddInfo(array $fields): ?AddResult
	{
		$infoFields = DocumentInfoTable::createObject()
			->setExternalHash($fields['EXTERNAL_HASH'])
			->setObjectId($fields['OBJECT_ID'])
			->setVersionId($fields['VERSION_ID'])
			->setOwnerId($fields['OWNER_ID'])
			->collectValues()
		;
		$result = new AddResult();
		DocumentInfoTable::checkFields($result, null, $infoFields);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$connection = DocumentInfoTable::getEntity()->getConnection();
		$sql = $connection->getSqlHelper()->prepareMerge(
			DocumentInfoTable::getTableName(),
			['EXTERNAL_HASH'],
			$infoFields,
			['EXTERNAL_HASH' => $infoFields['EXTERNAL_HASH']],
		);
		$connection->queryExecute($sql[0]);
		DocumentInfoTable::cleanCache();

		$info = DocumentInfoTable::getById($infoFields['EXTERNAL_HASH'])->fetch();
		if (!$info)
		{
			return null;
		}

		$result->setPrimary(['EXTERNAL_HASH' => $info['EXTERNAL_HASH']]);
		$result->setData($info);

		return $result;
	}

	/**
	 * A Vibeoffice row that carries an unresolved open attempt is deleted only logically: it stays as an
	 * inactive tombstone, the durable carrier of the attempt. The carrier is checked and deleted under the
	 * row lock the registry takes before it changes the attempt, so a send admitted meanwhile is not lost.
	 * Registry rows are not locked here: their writers take them in either order with the session row, under
	 * the document lock only. The settled rows of a deleted carrier age out by the maintenance.
	 */
	public static function delete($primary)
	{
		$id = (int)(is_array($primary) ? ($primary['ID'] ?? 0) : $primary);
		$row = $id > 0 ? static::query()->setSelect(['SERVICE'])->where('ID', $id)->setLimit(1)->fetch() : null;
		if (($row['SERVICE'] ?? null) !== DocumentService::Vibeoffice->value)
		{
			return parent::delete($primary);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if (OpenDebtCarrier::filterProtectedRows(self::lockVibeofficeRows([$id])) !== [])
			{
				self::deactivateByIds([$id]);
				$result = new DeleteResult();
			}
			else
			{
				$result = parent::delete($primary);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollbackKeepingCause($connection);

			throw $exception;
		}

		return $result;
	}

	public static function deleteBatch(array $filter)
	{
		if (
			$filter === []
			|| (array_key_exists('SERVICE', $filter) && $filter['SERVICE'] !== DocumentService::Vibeoffice->value)
		)
		{
			parent::deleteBatch($filter);

			return;
		}
		$where = [];
		foreach ($filter as $key => $value)
		{
			$where['=' . $key] = $value;
		}
		$where['=SERVICE'] = DocumentService::Vibeoffice->value;
		$vibeofficeIds = array_map('intval', array_column(static::getList([
			'select' => ['ID'],
			'filter' => $where,
		])->fetchAll(), 'ID'));
		if ($vibeofficeIds === [])
		{
			parent::deleteBatch($filter);

			return;
		}
		sort($vibeofficeIds);

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			// One small portion of contexts is held at a time; the row locks stay until the commit.
			foreach (array_chunk($vibeofficeIds, self::DELETE_CHUNK_SIZE) as $chunk)
			{
				$lockedIds = [];
				$protected = [];
				foreach (array_chunk($chunk, self::CONTEXT_READ_CHUNK_SIZE) as $portion)
				{
					$locked = self::lockVibeofficeRows($portion);
					array_push($protected, ...OpenDebtCarrier::filterProtectedRows($locked));
					array_push($lockedIds, ...array_map('intval', array_column($locked, 'ID')));
					unset($locked);
				}
				self::deactivateByIds($protected);
				$deletable = array_values(array_diff($lockedIds, $protected));
				if ($deletable !== [])
				{
					$connection->queryExecute(
						'DELETE FROM ' . $connection->getSqlHelper()->quote(static::getTableName())
						. ' WHERE ID IN (' . implode(', ', $deletable) . ')'
					);
				}
			}
			self::deleteOtherServicesBatch($filter);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollbackKeepingCause($connection);

			throw $exception;
		}
	}

	/**
	 * Locks the Vibeoffice rows among the given IDs in the order of their keys and reads their CONTEXT.
	 *
	 * @param int[] $ids
	 * @return array[]
	 */
	private static function lockVibeofficeRows(array $ids): array
	{
		$ids = array_values(array_unique($ids));
		sort($ids);
		$rows = [];
		foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk)
		{
			array_push($rows, ...DocumentStateRepository::fetchAllForUpdate(static::query()
				->setSelect(['ID', 'CONTEXT'])
				->whereIn('ID', $chunk)
				->where('SERVICE', DocumentService::Vibeoffice->value)
				->setOrder(['ID' => 'ASC'])
				->setLimit(count($chunk))));
		}

		return $rows;
	}

	/** A failed statement may have rolled the whole transaction back already: its error is the one to keep. */
	private static function rollbackKeepingCause(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}

	/** The rows of the filter that are not Vibeoffice ones, in one statement. */
	private static function deleteOtherServicesBatch(array $filter): void
	{
		$tableName = static::getTableName();
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$where = [];
		foreach ($filter as $key => $value)
		{
			$where[] = $helper->prepareAssignment($tableName, $key, $value);
		}
		$service = $helper->quote('SERVICE');
		$where[] = "({$service} IS NULL OR {$service} <> '"
			. $helper->forSql(DocumentService::Vibeoffice->value) . "')";

		$connection->queryExecute('DELETE FROM ' . $helper->quote($tableName) . ' WHERE ' . implode(' AND ', $where));
	}

	public static function deleteByService(DocumentService $service): void
	{
		static::deleteBatch([
			'SERVICE' => $service->value,
		]);
	}

	public static function deactivateByHash(string $hash): void
	{
		parent::updateBatch(
			[
				'STATUS' => self::STATUS_NON_ACTIVE,
			],
			[
				'=EXTERNAL_HASH' => $hash,
			]
		);
	}

	public static function deactivateByIds(array $sessionIds): void
	{
		if ($sessionIds === [])
		{
			return;
		}

		parent::updateBatch(
			[
				'STATUS' => self::STATUS_NON_ACTIVE,
			],
			[
				'@ID' => $sessionIds,
			]
		);
	}

	/**
	 * The same for a whole portion of keys, asked of the database once.
	 *
	 * @param string[] $hashes
	 */
	public static function deactivateByHashes(array $hashes): void
	{
		if (empty($hashes))
		{
			return;
		}

		parent::updateBatch(
			[
				'STATUS' => self::STATUS_NON_ACTIVE,
			],
			[
				'@EXTERNAL_HASH' => $hashes,
			]
		);
	}

	public static function markAsBrokenByHash(string $hash): void
	{
		parent::updateBatch(
			[
				'STATUS' => self::STATUS_BROKEN,
			],
			[
				'=EXTERNAL_HASH' => $hash,
			]
		);
	}
}
