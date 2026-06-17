<?php

namespace Bitrix\Voximplant\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Queue of CALL_IDs successfully applied by CallHistoryPuller agent,
 * awaiting ACK to the controller on the next pull request.
 */
class CallHistoryAckTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_voximplant_callhistory_ack';
	}

	public static function getMap()
	{
		return [
			new IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new StringField('CALL_ID', [
				'required' => true,
			]),
			new DatetimeField('APPLIED_AT', [
				'required' => true,
			]),
		];
	}
}
