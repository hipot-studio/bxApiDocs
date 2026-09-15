<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Models;

use Bitrix\Main\ORM;

/**
 * Class RequestTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Request_Query query()
 * @method static EO_Request_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Request_Result getById($id)
 * @method static EO_Request_Result getList(array $parameters = [])
 * @method static EO_Request_Entity getEntity()
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Request createObject($setDefaultValues = true)
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection createCollection()
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Request wakeUpObject($row)
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection wakeUpCollection($rows)
 */
class RequestTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_anonymizer_request';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('QUEST_ID'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('COMMAND'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('HASH'))
				->configureNullable()
				->configureDefaultValue(null)
			,
			(new ORM\Fields\ArrayField('RESULT'))
				->configureNullable()
				->configureDefaultValue(null)
			,
			(new ORM\Fields\TextField('ERROR'))
				->configureNullable()
				->configureDefaultValue(null)
			,
		];
	}

	/**
	 * Returns one request row by quest and command (at most one row per quest+command).
	 *
	 * @return array|null Row with ID, HASH, COMMAND, QUEST_ID, RESULT, ERROR or null
	 */
	public static function getRowByQuestAndCommand(int $questId, string $commandCode): ?array
	{
		$row = static::query()
			->setSelect(['ID', 'HASH', 'COMMAND', 'QUEST_ID', 'RESULT', 'ERROR'])
			->where('QUEST_ID', $questId)
			->where('COMMAND', $commandCode)
			->setLimit(1)
			->fetch();

		return $row ?: null;
	}
}
