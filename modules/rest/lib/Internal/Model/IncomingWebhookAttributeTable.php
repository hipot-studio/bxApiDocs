<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\Merge;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\Internal\Integration\Bitrix24\LicenseScannerStateInvalidator;

/**
 * Class IncomingWebhookAttributeTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_IncomingWebhookAttribute_Query query()
 * @method static EO_IncomingWebhookAttribute_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_IncomingWebhookAttribute_Result getById($id)
 * @method static EO_IncomingWebhookAttribute_Result getList(array $parameters = [])
 * @method static EO_IncomingWebhookAttribute_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_IncomingWebhookAttribute createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_IncomingWebhookAttribute_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_IncomingWebhookAttribute wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_IncomingWebhookAttribute_Collection wakeUpCollection($rows)
 */
class IncomingWebhookAttributeTable extends DataManager
{
	use AddMergeTrait;

	protected static function getMergeStrategy(): AddStrategy
	{
		return new Merge(static::getEntity(), ['PASSWORD_ID', 'TYPE', 'CODE']);
	}

	public static function getTableName(): string
	{
		return 'b_rest_incoming_webhook_attribute';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('PASSWORD_ID'))
				->configureRequired(),

			(new StringField('TYPE'))
				->configureRequired()
				->configureSize(1)
				->configureDefaultValue('S')
			,

			(new StringField('CODE'))
				->configureRequired()
				->configureSize(50)
			,

			(new StringField('VALUE'))
				->configureNullable()
				->configureSize(1000)
			,

			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static function () {
					return new Main\Type\DateTime();
				}),

			(new Reference(
				'PASSWORD',
				PasswordTable::class,
				Join::on('this.PASSWORD_ID', 'ref.ID'),
			)),
		];
	}

	public static function deleteByPasswordId(int $passwordId): void
	{
		$connection = Main\Application::getConnection();
		$connection->queryExecute(
			sprintf(
				'DELETE FROM %s WHERE PASSWORD_ID = %d',
				static::getTableName(),
				$passwordId,
			),
		);
		self::resetScannerComputedState();
	}

	/**
	 * @param string[] $codes
	 */
	public static function deleteOrphansByPasswordId(int $passwordId, string $type, array $codes): void
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		if (empty($codes))
		{
			$connection->queryExecute(
				sprintf(
					'DELETE FROM %s WHERE PASSWORD_ID = %d AND TYPE = \'%s\'',
					static::getTableName(),
					$passwordId,
					$helper->forSql($type),
				),
			);
			self::resetScannerComputedState();

			return;
		}

		$escapedCodes = implode(
			',',
			array_map(
				static fn(string $code) => "'" . $helper->forSql($code) . "'",
				$codes,
			),
		);

		$connection->queryExecute(
			sprintf(
				'DELETE FROM %s WHERE PASSWORD_ID = %d AND TYPE = \'%s\' AND CODE NOT IN (%s)',
				static::getTableName(),
				$passwordId,
				$helper->forSql($type),
				$escapedCodes,
			),
		);
		self::resetScannerComputedState();
	}

	public static function onAfterAdd(Main\ORM\Event $event): void
	{
		self::resetScannerComputedState();
	}

	public static function onAfterUpdate(Main\ORM\Event $event): void
	{
		self::resetScannerComputedState();
	}

	public static function onAfterDelete(Main\ORM\Event $event): void
	{
		self::resetScannerComputedState();
	}

	private static function resetScannerComputedState(): void
	{
		LicenseScannerStateInvalidator::reset();
	}
}
