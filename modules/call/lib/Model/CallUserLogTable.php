<?php

namespace Bitrix\Call\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\AddStrategy\Merge;
use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

/**
 * Class CallUserLogTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CallUserLog_Query query()
 * @method static EO_CallUserLog_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CallUserLog_Result getById($id)
 * @method static EO_CallUserLog_Result getList(array $parameters = [])
 * @method static EO_CallUserLog_Entity getEntity()
 * @method static \Bitrix\Call\Model\EO_CallUserLog createObject($setDefaultValues = true)
 * @method static \Bitrix\Call\Model\EO_CallUserLog_Collection createCollection()
 * @method static \Bitrix\Call\Model\EO_CallUserLog wakeUpObject($row)
 * @method static \Bitrix\Call\Model\EO_CallUserLog_Collection wakeUpCollection($rows)
 */
class CallUserLogTable extends DataManager
{
	use AddMergeTrait;
	use DeleteByFilterTrait;
	public const STATUS_INITIATED = 'initiated';
	public const STATUS_ANSWERED = 'answered';
	public const STATUS_DECLINED = 'declined';
	public const STATUS_MISSED = 'missed';

	public const SOURCE_TYPE_CALL = 'call';
	public const SOURCE_TYPE_VOXIMPLANT = 'voximplant';

	protected static function getMergeStrategy(): AddStrategy
	{
		return new Merge(
			static::getEntity(),
			['SOURCE_TYPE', 'SOURCE_CALL_ID', 'USER_ID']
		);
	}

	public static function getTableName(): string
	{
		return 'b_call_userlog';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('SOURCE_TYPE'))
				->configureRequired()
				->configureSize(64),

			(new IntegerField('SOURCE_CALL_ID'))
				->configureRequired(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new StringField('STATUS'))
				->configureRequired()
				->configureSize(64),

			(new DatetimeField('STATUS_TIME'))
				->configureRequired()
				->configureDefaultValue(function()
				{
					return new DateTime();
				}),

			// Relations
			(new Reference(
				'USER',
				\Bitrix\Main\UserTable::class,
				Join::on('this.USER_ID', 'ref.ID')
			)),

			(new Reference(
				'IM_CALL',
				\Bitrix\Im\Model\CallTable::class,
				Join::on('this.SOURCE_CALL_ID', 'ref.ID')
					->where('this.SOURCE_TYPE', self::SOURCE_TYPE_CALL)
			)),

			(new Reference(
				'VOXIMPLANT_STAT',
				\Bitrix\Voximplant\StatisticTable::class,
				Join::on('this.SOURCE_CALL_ID', 'ref.ID')
					->where('this.SOURCE_TYPE', self::SOURCE_TYPE_VOXIMPLANT)
			)),
		];
	}

	public static function addIndexRecord(\Bitrix\Call\Internals\CallLogIndex $index): void
	{
		if ($index->getCallLogId() === 0)
		{
			return;
		}

		$insertData = self::prepareParamsForIndex($index);

		try
		{
			CallUserLogIndexTable::add($insertData);
		}
		catch (\Bitrix\Main\DB\SqlQueryException)
		{
			self::updateIndexRecord($index);
		}
	}

	public static function updateIndexRecord(\Bitrix\Call\Internals\CallLogIndex $index): void
	{
		if ($index->getCallLogId() === 0)
		{
			return;
		}

		$updateData = self::prepareParamsForIndex($index);

		CallUserLogIndexTable::updateIndex(
			$index->getCallLogId(),
			'USERLOG_ID',
			$updateData
		);
	}

	private static function prepareParamsForIndex(\Bitrix\Call\Internals\CallLogIndex $index): array
	{
		return [
			'USERLOG_ID' => $index->getCallLogId(),
			'SEARCH_CONTENT' => \Bitrix\Main\Search\MapBuilder::create()->addText(self::generateSearchContent($index))->build(),
			'SEARCH_TITLE' => \Bitrix\Main\Search\MapBuilder::create()->addText(self::generateSearchTitle($index))->build(),
		];
	}

	public static function generateSearchContent(\Bitrix\Call\Internals\CallLogIndex $index): string
	{
		$parts = [];

		if (!empty($index->getClearedTitle()))
		{
			$parts[] = $index->getClearedTitle();
		}

		$userNames = $index->getClearedUserNames();
		if (!empty($userNames))
		{
			$parts = array_merge($parts, $userNames);
		}

		$phoneNumbers = $index->getClearedPhoneNumbers();
		if (!empty($phoneNumbers))
		{
			$parts = array_merge($parts, $phoneNumbers);
		}

		return implode(' ', $parts);
	}

	public static function generateSearchTitle(\Bitrix\Call\Internals\CallLogIndex $index): string
	{
		$title = $index->getClearedTitle();
		if (!empty($title))
		{
			return $title;
		}

		$userNames = $index->getClearedUserNames();
		if (!empty($userNames))
		{
			return $userNames[0];
		}

		$phoneNumbers = $index->getClearedPhoneNumbers();
		if (!empty($phoneNumbers))
		{
			return $phoneNumbers[0];
		}

		return '';
	}
}
