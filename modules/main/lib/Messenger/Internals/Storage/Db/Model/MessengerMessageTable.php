<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals\Storage\Db\Model;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Class MessageStorageTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MessengerMessage_Query query()
 * @method static EO_MessengerMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MessengerMessage_Result getById($id)
 * @method static EO_MessengerMessage_Result getList(array $parameters = [])
 * @method static EO_MessengerMessage_Entity getEntity()
 * @method static \Bitrix\Main\Messenger\Internals\Storage\Db\Model\EO_MessengerMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\Messenger\Internals\Storage\Db\Model\EO_MessengerMessage_Collection createCollection()
 * @method static \Bitrix\Main\Messenger\Internals\Storage\Db\Model\EO_MessengerMessage wakeUpObject($row)
 * @method static \Bitrix\Main\Messenger\Internals\Storage\Db\Model\EO_MessengerMessage_Collection wakeUpCollection($rows)
 */
class MessengerMessageTable extends DataManager
{
	protected const REQUEUE_BATCH_SIZE = 500;
	protected const REQUEUE_MAX_BATCHES = 20;
	private const REQUEUE_LOGGER_ID = 'main.Messenger.Requeue';

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();

		$result->modifyFields(['UPDATED_AT' => new DateTime()]);

		return $result;
	}

	public static function getTableName(): string
	{
		return 'b_main_messenger_message';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('QUEUE_ID'))
				->configureRequired()
				->configureSize(255),

			(new StringField('ITEM_ID'))
				->configureNullable()
				->configureSize(64),

			(new StringField('CLASS'))
				->configureRequired(),

			(new TextField('PAYLOAD'))
				->configureRequired(),

			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow(),

			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValueNow(),

			(new IntegerField('TTL'))
				->configureRequired(),

			(new DatetimeField('AVAILABLE_AT'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime()),

			(new StringField('STATUS'))
				->configureRequired()
				->configureDefaultValue(MessageStatus::New->value)
				->configureSize(10),
		];
	}

	/**
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public static function requeueStaleMessages(DateTime $thresholdDate): void
	{
		$connection = static::getEntity()->getConnection();

		for ($batch = 0; $batch < static::REQUEUE_MAX_BATCHES; $batch++)
		{
			$rows = static::query()
				->setSelect(['ID'])
				->where('STATUS', MessageStatus::Processing->value)
				->where('UPDATED_AT', '<', $thresholdDate)
				->setOrder(['ID' => 'ASC'])
				->setLimit(static::REQUEUE_BATCH_SIZE)
				->fetchAll()
			;

			if (!$rows)
			{
				return;
			}

			$ids = array_map('intval', array_column($rows, 'ID'));
			$idList = implode(',', $ids);

			$sql = new SqlExpression(
				'UPDATE ?# SET STATUS = ?s, UPDATED_AT = ? WHERE ID IN (' . $idList . ') AND STATUS = ?s',
				static::getTableName(),
				MessageStatus::New->value,
				new DateTime(),
				MessageStatus::Processing->value,
			);

			$connection->queryExecute($sql->compile());

			if (count($rows) < static::REQUEUE_BATCH_SIZE)
			{
				return;
			}
		}

		// Reached the batch limit - the remainder will be picked up on the next run after cache TTL expires
		(new LoggerFactory())
			->createById(self::REQUEUE_LOGGER_ID)
			->warning(
				sprintf(
					'The requeueStaleMessages reached batch limit (%d x %d) for table "%s". Remainder will be processed on next run',
					static::REQUEUE_MAX_BATCHES,
					static::REQUEUE_BATCH_SIZE,
					static::getTableName(),
				),
			)
		;
	}
}
