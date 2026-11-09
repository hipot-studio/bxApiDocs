<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * One-time tokens carrying a request of the chat to /sites/ai/ or to the editor of a site. Only the sha256
 * hash of a token is stored; the token itself exists only in the link.
 */
class AiPromptHandoffTable extends DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName(): string
	{
		return 'b_landing_ai_prompt_handoff';
	}

	/**
	 * @inheritdoc
	 */
	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new Fields\StringField('TOKEN_HASH'))
				->configureRequired()
			,
			(new Fields\IntegerField('USER_ID'))
				->configureRequired()
			,
			(new Fields\StringField('TARGET'))
				->configureRequired()
			,
			(new Fields\IntegerField('SITE_ID'))
				->configureNullable()
			,
			(new Fields\IntegerField('BINDING_ID'))
				->configureNullable()
			,
			(new Fields\TextField('PROMPT'))
				->configureNullable()
			,
			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
			,
			(new Fields\DatetimeField('DATE_EXPIRE'))
				->configureRequired()
			,
			(new Fields\DatetimeField('DATE_USED'))
				->configureNullable()
			,
		];
	}
}
