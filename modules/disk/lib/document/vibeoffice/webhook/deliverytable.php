<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Disk\Document\Vibeoffice\MaintenanceAgent;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\EventLog\Internal\EventLogTable;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Idempotency ledger of processed vibeoffice webhook deliveries (P5.T1, SQL-DELIVERY).
 *
 * Retries resume pending/staged/applied work; DONE may still need after-commit cleanup.
 * Background maintenance preserves delivery rows required for replay.
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
	public const STATUS_PENDING = 'pending';
	public const STATUS_STAGED = 'staged';
	public const STATUS_APPLIED = 'applied';
	public const STATUS_DONE = 'done';
	public const OUTCOME_APPLIED = 'applied';
	public const OUTCOME_REFUSED = 'refused';
	public const OUTCOME_IGNORED = 'ignored';
	private const SAFE_REFUSAL_REASONS = [
		'validation', 'ssrf_blocked', 'vibeoffice_file_too_large', 'vibeoffice_lock_order',
		'vibeoffice_registry_failed', 'session_missing', 'file_missing', 'file_too_large',
		'format_restricted', 'stale_snapshot', 'foreign_scope',
	];
	private const RESOLUTION_AUDIT_TYPE = 'DISK_VO_SAVED_RESOLUTION';

	public static function getTableName()
	{
		return 'b_disk_vibeoffice_delivery';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configureSize(8)
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('DELIVERY_ID'))
				->configureSize(8)
				->configureRequired()
			,
			(new StringField('EXTERNAL_HASH'))
				->configureSize(128)
			,
			(new StringField('HOST_DOC_KEY'))->configureNullable()->configureSize(128),
			(new StringField('STATUS'))->configureSize(24)->configureDefaultValue(self::STATUS_DONE),
			(new StringField('OUTCOME'))->configureSize(16)->configureNullable(),
			(new StringField('REASON'))->configureSize(64)->configureNullable(),
			(new IntegerField('SNAPSHOT_SEQ'))->configureSize(8)->configureNullable(),
			(new IntegerField('RESULT_OBJECT_ID'))->configureNullable(),
			(new IntegerField('RESULT_VERSION_ID'))->configureNullable(),
			(new IntegerField('STAGED_FILE_ID'))->configureNullable(),
			(new TextField('CLEANUP_FILE_IDS'))->configureNullable(),
			(new IntegerField('NOTIFIED'))->configureDefaultValue(0),
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

	public static function findLatestSafeRefusalReason(string $hostDocKey): ?string
	{
		$row = self::query()
			->setSelect(['STATUS', 'OUTCOME', 'REASON'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('EVENT', WebhookProcessor::EVENT_SAVED)
			->setOrder(['SNAPSHOT_SEQ' => 'DESC', 'DELIVERY_ID' => 'DESC'])
			->setLimit(1)
			->fetch();
		if (!$row || $row['STATUS'] !== self::STATUS_DONE
			|| !in_array($row['OUTCOME'], [self::OUTCOME_REFUSED, self::OUTCOME_IGNORED], true))
		{
			return null;
		}

		return in_array($row['REASON'], self::SAFE_REFUSAL_REASONS, true) ? $row['REASON'] : null;
	}

	/** The operator supplies the latest open DS obligation after checking it there. */
	public static function resolveUnappliedOutcome(
		string $hostDocKey,
		int $deliveryId,
		int $snapshotSeq,
		int $operatorId,
	): Result
	{
		global $USER;
		if ($hostDocKey === '' || $deliveryId <= 0 || $snapshotSeq <= 0 || $operatorId <= 0
			|| !is_object($USER) || !$USER->IsAdmin() || (int)$USER->GetID() !== $operatorId)
		{
			return (new Result())->addError(new Error('Saved outcome resolution is not authorized.', 'access_denied'));
		}
		$connection = Application::getConnection();
		$lockName = DocumentStateRepository::getDocumentLockName($hostDocKey);
		if (!$connection->lock($lockName, 5))
		{
			return (new Result())->addError(new Error('Could not lock the saved document.', 'vibeoffice_lock_timeout'));
		}
		try
		{
			$connection->startTransaction();
			try
			{
				$latest = DocumentStateRepository::fetchForUpdate(self::query()->setSelect(['*'])
					->where('HOST_DOC_KEY', $hostDocKey)
					->where('EVENT', WebhookProcessor::EVENT_SAVED)
					->setOrder(['SNAPSHOT_SEQ' => 'DESC', 'DELIVERY_ID' => 'DESC'])
					->setLimit(1));
				if (!$latest || (int)$latest['DELIVERY_ID'] !== $deliveryId
					|| (int)$latest['SNAPSHOT_SEQ'] !== $snapshotSeq
					|| $latest['STATUS'] !== self::STATUS_DONE
					|| !in_array($latest['OUTCOME'], [self::OUTCOME_REFUSED, self::OUTCOME_IGNORED], true))
				{
					$connection->commitTransaction();

					return (new Result())->addError(new Error('Delivery is not the latest unresolved saved outcome.', 'resolution_not_allowed'));
				}
				$updated = self::update((int)$latest['ID'], [
					'STATUS' => (int)$latest['STAGED_FILE_ID'] > 0 ? self::STATUS_STAGED : self::STATUS_PENDING,
					'OUTCOME' => null,
					'REASON' => null,
					'RESULT_OBJECT_ID' => null,
					'RESULT_VERSION_ID' => null,
					'NOTIFIED' => 0,
				]);
				if (!$updated->isSuccess())
				{
					$connection->rollbackTransaction();

					return (new Result())->addErrors($updated->getErrors());
				}
				$audit = EventLogTable::add([
					'TIMESTAMP_X' => new DateTime(),
					'SEVERITY' => 'INFO',
					'AUDIT_TYPE_ID' => self::RESOLUTION_AUDIT_TYPE,
					'MODULE_ID' => 'disk',
					'ITEM_ID' => (string)$deliveryId,
					'USER_ID' => $operatorId,
					'DESCRIPTION' => json_encode([
						'hostDocKey' => $hostDocKey,
						'snapshotSeq' => $snapshotSeq,
						'oldOutcome' => $latest['OUTCOME'],
						'oldReason' => $latest['REASON'],
					], JSON_THROW_ON_ERROR),
				]);
				if (!$audit->isSuccess())
				{
					$connection->rollbackTransaction();

					return (new Result())->addErrors($audit->getErrors());
				}
				$connection->commitTransaction();

				return (new Result())->setData(['auditId' => (int)$audit->getId()]);
			}
			catch (\Throwable)
			{
				$connection->rollbackTransaction();

				return (new Result())->addError(new Error('Could not resolve saved outcome.', 'resolution_failed'));
			}
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public static function deleteOldDeliveriesAgent(): string
	{
		self::logMaintenancePass((new MaintenanceAgent())->run());

		return self::class . '::deleteOldDeliveriesAgent();';
	}

	public static function deleteOldDeliveries(?DateTime $now = null): void
	{
		self::logMaintenancePass((new MaintenanceAgent())->run($now));
	}

	/**
	 * Neither entry point returns the report, so the log of the agent is the only trace of a pass.
	 * An incomplete pass is worth an operator's attention and goes one level above a plain one.
	 */
	private static function logMaintenancePass(Result $result): void
	{
		$report = $result->getData();
		$complete = (bool)($report['complete'] ?? false);
		$message =
			'Vibeoffice maintenance pass in mode {mode} took {elapsed} s: complete={complete}, busy={busy},'
			. ' deleted {deleted}, skipped {skipped}, categories {categories}.'
		;
		$context = [
			'mode' => (string)($report['mode'] ?? ''),
			'elapsed' => (string)($report['elapsedSeconds'] ?? ''),
			'complete' => self::formatFlag($complete),
			'busy' => self::formatFlag((bool)($report['busy'] ?? false)),
			'deleted' => self::formatCounters((array)($report['deleted'] ?? [])),
			'skipped' => self::formatCounters((array)($report['skipped'] ?? [])),
			'categories' => self::formatCounters((array)($report['categories'] ?? [])),
		];

		$logger = MaintenanceAgent::logger();
		if ($complete)
		{
			$logger?->info($message, $context);
		}
		else
		{
			$logger?->warning($message, $context);
		}
	}

	private static function formatCounters(array $counters): string
	{
		$parts = [];
		foreach ($counters as $name => $value)
		{
			$parts[] = $name . '=' . (is_bool($value) ? self::formatFlag($value) : (string)$value);
		}

		return $parts ? implode(', ', $parts) : 'none';
	}

	private static function formatFlag(bool $flag): string
	{
		return $flag ? 'yes' : 'no';
	}
}
