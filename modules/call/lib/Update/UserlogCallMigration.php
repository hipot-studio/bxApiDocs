<?php

namespace Bitrix\Call\Update;

use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Config\Option;

/**
 * @internal
 */
class UserlogCallMigration extends Stepper
{
	protected static $moduleId = 'call';

	public function execute(array &$option): bool
	{
		\Bitrix\Main\Loader::includeModule('call');

		$connection = \Bitrix\Main\Application::getConnection();
		if (
			!$connection->isTableExists('b_call_session')
			|| !$connection->isTableExists('b_call_userlog')
		)
		{
			return self::CONTINUE_EXECUTION;
		}

		if ($this->getPreviousStep() < 1)
		{
			$this->step1();
		}

		$option['steps'] = $this->getPreviousStep();
		$option['count'] = 1;

		return self::FINISH_EXECUTION;
	}

	private function step1(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		if ($connection->getType() == 'mysql')
		{
			$connection->queryExecute("
				INSERT IGNORE INTO b_call_session (
					ID, TYPE, SCHEME, INITIATOR_ID, IS_PUBLIC, PUBLIC_ID, PROVIDER, ENTITY_TYPE, ENTITY_ID, PARENT_ID, PARENT_UUID, 
					STATE, START_DATE, END_DATE, CHAT_ID, LOG_URL, UUID, SECRET_KEY, ENDPOINT, RECORD_AUDIO, AI_ANALYZE
				)
				SELECT 
					ID, TYPE, SCHEME, INITIATOR_ID, IS_PUBLIC, PUBLIC_ID, PROVIDER, ENTITY_TYPE, ENTITY_ID, PARENT_ID, PARENT_UUID, 
					STATE, START_DATE, END_DATE, CHAT_ID, LOG_URL, UUID, SECRET_KEY, ENDPOINT, RECORD_AUDIO, AI_ANALYZE
				FROM b_im_call 
				WHERE
					ID IN (SELECT SOURCE_CALL_ID FROM b_call_userlog WHERE SOURCE_TYPE = 'call')
					AND ID NOT IN (SELECT ID FROM b_call_session)
			");
		}
		elseif ($connection->getType() == 'pgsql')
		{
			// language=PostgreSQL
			$connection->queryExecute("
				INSERT INTO b_call_session (
					id, type, scheme, initiator_id, is_public, public_id, provider, entity_type, entity_id, parent_id, parent_uuid, 
					state, start_date, end_date, chat_id, log_url, uuid, secret_key, endpoint, record_audio, ai_analyze
				)
				SELECT
					id, type, scheme, initiator_id, is_public, public_id, provider, entity_type, entity_id, parent_id, parent_uuid, 
					state, start_date, end_date, chat_id, log_url, uuid, secret_key, endpoint, record_audio, ai_analyze
				FROM b_im_call
				WHERE
					id IN (SELECT source_call_id FROM b_call_userlog WHERE source_type = 'call')
					AND id NOT IN (SELECT id FROM b_call_session)
				ON CONFLICT (id) DO NOTHING
			");
		}
		$this->setCurrentStep(1);
	}

	private function getPreviousStep(): int
	{
		return (int)Option::get('call', 'userlogs_call_db_migrated', 0);
	}

	private function setCurrentStep(int $step): void
	{
		Option::set('call', 'userlogs_call_db_migrated', $step);
	}
}

