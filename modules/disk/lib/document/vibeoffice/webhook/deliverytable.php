<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Idempotency ledger of processed vibeoffice webhook deliveries (P5.T1, SQL-DELIVERY).
 *
 * Each incoming webhook carries an `X-VO-Delivery` id; the platform retries delivery
 * with the same id (api-reference §6.2). A row is inserted here keyed by `DELIVERY_ID`
 * with a UNIQUE constraint, so a duplicate insert fails and the webhook is acknowledged
 * with an early 2xx without re-processing.
 *
 * The table is additive and isolated from the OnlyOffice webhook path; old rows are
 * pruned by a background agent (TTL is not normative — see SQL-DELIVERY).
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Delivery_Query query()
 * @method static EO_Delivery_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Delivery_Result getById($id)
 * @method static EO_Delivery_Result getList(array $parameters = [])
 * @method static EO_Delivery_Entity getEntity()
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery_Collection createCollection()
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery wakeUpObject($row)
 * @method static \Bitrix\Disk\Document\Vibeoffice\Webhook\EO_Delivery_Collection wakeUpCollection($rows)
 */
final class DeliveryTable extends DataManager
{
	/** Retention of processed-delivery rows (seconds) before background cleanup. */
	private const TTL = 7 * 24 * 3600;

	/** Rows removed per DELETE so the cleanup never locks the whole ledger with one big statement. */
	private const DELETE_CHUNK_SIZE = 1000;

	public static function getTableName()
	{
		return 'b_disk_vibeoffice_delivery';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('DELIVERY_ID'))
				->configureRequired()
			,
			(new StringField('EXTERNAL_HASH'))
				->configureSize(128)
			,
			(new StringField('EVENT'))
				->configureRequired()
				->configureSize(64)
			,
			(new DatetimeField('PROCESSED_TIME'))
				->configureRequired()
				->configureDefaultValue(static function() {
					return new DateTime();
				})
			,
		];
	}

	/**
	 * Background agent that prunes old processed-delivery rows (idempotency ledger).
	 *
	 * Modeled on {@see \Bitrix\Disk\Document\OnlyOffice\RestrictionManager::deleteOldOrPendingAgent()};
	 * TTL is not normative (SQL-DELIVERY). Self-rescheduling agent.
	 */
	public static function deleteOldDeliveriesAgent(): string
	{
		self::deleteOldDeliveries();

		return self::class . '::deleteOldDeliveriesAgent();';
	}

	public static function deleteOldDeliveries(): void
	{
		$connection = Application::getConnection();
		$tableName = self::getTableName();
		$ttlTime = $connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL)
		);

		// Delete in bounded batches (supported by both MySQL and PostgreSQL via SELECT ... LIMIT,
		// unlike DELETE ... LIMIT which is MySQL-only) so a large backlog never blocks the ledger
		// with one long-running DELETE. The PROCESSED_TIME index keeps each batch's scan cheap.
		do
		{
			$ids = $connection->query("
				SELECT ID
				FROM {$tableName}
				WHERE PROCESSED_TIME < {$ttlTime}
				LIMIT " . self::DELETE_CHUNK_SIZE
			)->fetchAll();

			$ids = array_map('intval', array_column($ids, 'ID'));
			if (!$ids)
			{
				break;
			}

			$connection->queryExecute(
				(new SqlExpression(
					'DELETE FROM ?# WHERE ID IN (?@)',
					$tableName,
					$ids,
				))->compile()
			);
		}
		while (count($ids) === self::DELETE_CHUNK_SIZE);
	}
}
