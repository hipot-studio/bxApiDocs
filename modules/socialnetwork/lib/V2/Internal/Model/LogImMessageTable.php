<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class LogImMessageTable
 *
 * Mapping "feed post <-> system message in the project (collab) chat".
 * Owner of the link storage (table) contract. One post (LOG_ID) may be published to several project
 * chats (cross-post to several groups), so a link is unique per (LOG_ID,
 * IM_CHAT_ID) pair — a composite PK. In each chat the post is represented by
 * exactly one system message (IM_MESSAGE_ID — globally unique).
 *
 * Fields:
 * <ul>
 * <li> LOG_ID int mandatory (PK part)
 * <li> IM_CHAT_ID int mandatory (PK part)
 * <li> IM_MESSAGE_ID int mandatory (unique)
 * <li> GROUP_ID int mandatory
 * <li> DATE_CREATE datetime mandatory
 * </ul>
 *
 * @package Bitrix\Socialnetwork\V2\Internal\Model
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_LogImMessage_Query query()
 * @method static EO_LogImMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_LogImMessage_Result getById($id)
 * @method static EO_LogImMessage_Result getList(array $parameters = [])
 * @method static EO_LogImMessage_Entity getEntity()
 * @method static \Bitrix\Socialnetwork\V2\Internal\Model\EO_LogImMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Socialnetwork\V2\Internal\Model\EO_LogImMessage_Collection createCollection()
 * @method static \Bitrix\Socialnetwork\V2\Internal\Model\EO_LogImMessage wakeUpObject($row)
 * @method static \Bitrix\Socialnetwork\V2\Internal\Model\EO_LogImMessage_Collection wakeUpCollection($rows)
 */
class LogImMessageTable extends DataManager
{
	use MergeTrait;
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_sonet_log_im_message';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('LOG_ID'))
				->configurePrimary(true)
			,
			(new IntegerField('IM_CHAT_ID'))
				->configurePrimary(true)
			,
			(new IntegerField('IM_MESSAGE_ID'))
				->configureRequired(true)
				->configureUnique(true)
			,
			(new IntegerField('GROUP_ID'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureRequired(true)
			,
		];
	}
}
