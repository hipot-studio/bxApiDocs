<?php

namespace Bitrix\Call\Update;

use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Config\Option;

/**
 * @internal
 */
class ImCallMigration extends Stepper
{
	protected static $moduleId = 'call';

	public function execute(array &$option): bool
	{
		\Bitrix\Main\Loader::includeModule('call');

		$connection = \Bitrix\Main\Application::getConnection();
		if (
			!$connection->isTableExists('b_call_session')
			|| !$connection->isTableExists('b_call_user')
			|| !$connection->isTableExists('b_call_conference')
			|| !$connection->isTableExists('b_call_conference_user_role')
		)
		{
			return self::CONTINUE_EXECUTION;
		}

		if ($this->getPreviousStep() < 1)
		{
			$this->step1();
		}
		if ($this->getPreviousStep() < 2)
		{
			$this->step2();
		}
		if ($this->getPreviousStep() < 3)
		{
			$this->step3();
		}
		if ($this->getPreviousStep() < 4)
		{
			$this->step4();
		}
		if ($this->getPreviousStep() < 5)
		{
			$this->step5();
		}

		$option['steps'] = $this->getPreviousStep();
		$option['count'] = 5;

		return self::FINISH_EXECUTION;
	}

	private function step1(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$res = $connection->query("SELECT MAX(ID) + 100 as CNT FROM b_im_call");
		if ($row = $res->fetch())
		{
			$maxId = (int)$row['CNT'];
			if ($maxId > 0)
			{
				if ($connection->getType() == 'mysql')
				{
					$connection->queryExecute("ALTER TABLE b_call_session AUTO_INCREMENT = {$maxId}");
				}
				elseif ($connection->getType() == 'pgsql')
				{
					// language=PostgreSQL
					$connection->queryExecute("ALTER SEQUENCE b_call_session_id_seq RESTART WITH {$maxId}");
				}
			}
		}
		$this->setCurrentStep(1);
	}

	private function step2(): void
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
					(START_DATE > DATE_SUB(NOW(), INTERVAL 12 HOUR))
					OR ID IN (SELECT CALL_ID FROM b_call_track)
					OR ID IN (SELECT CALL_ID FROM b_call_ai_task)
					OR ID IN (SELECT CALL_ID FROM b_call_outcome)
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
					(start_date > now() - INTERVAL '12 hours')
					OR id IN (SELECT call_id FROM b_call_track)
					OR id IN (SELECT call_id FROM b_call_ai_task)
					OR id IN (SELECT call_id FROM b_call_outcome)
				ON CONFLICT (id) DO NOTHING
			");
		}
		$this->setCurrentStep(2);
	}

	private function step3(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		if ($connection->getType() == 'mysql')
		{
			$connection->queryExecute("
				INSERT IGNORE INTO b_call_user (
					CALL_ID, USER_ID, STATE, LAST_SEEN, IS_MOBILE, FIRST_JOINED, RECORDED, SHARED_SCREEN
				)
				SELECT 
					CALL_ID, USER_ID, STATE, LAST_SEEN, IS_MOBILE, FIRST_JOINED, RECORDED, SHARED_SCREEN
				FROM b_im_call_user
				WHERE 
					CALL_ID IN (SELECT ID FROM b_call_session)
			");
		}
		elseif ($connection->getType() == 'pgsql')
		{
			// language=PostgreSQL
			$connection->queryExecute("
				INSERT INTO b_call_user (
					call_id, user_id, state, last_seen, is_mobile, first_joined, recorded, shared_screen
				)
				SELECT
					call_id, user_id, state, last_seen, is_mobile, first_joined, recorded, shared_screen
				FROM b_im_call_user
				WHERE 
					call_id IN (SELECT id FROM b_call_session)
				ON CONFLICT (call_id,  user_id) DO NOTHING
			");
		}
		$this->setCurrentStep(3);
	}

	private function step4(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		if ($connection->getType() == 'mysql')
		{
			$connection->queryExecute("
				INSERT IGNORE INTO b_call_conference (
					ID, ALIAS_ID, PASSWORD, CONFERENCE_START, CONFERENCE_END, INVITATION, IS_BROADCAST
				)
				SELECT 
					ID, ALIAS_ID, PASSWORD, CONFERENCE_START, CONFERENCE_END, INVITATION, IS_BROADCAST
				FROM b_im_conference
			");
		}
		elseif ($connection->getType() == 'pgsql')
		{
			// language=PostgreSQL
			$connection->queryExecute("
				INSERT INTO b_call_conference (
					id, alias_id, password, conference_start, conference_end, invitation, is_broadcast
				)
				SELECT
					id, alias_id, password, conference_start, conference_end, invitation, is_broadcast
				FROM b_im_conference
				ON CONFLICT (id) DO NOTHING
			");
		}
		$this->setCurrentStep(4);
	}

	private function step5(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		if ($connection->getType() == 'mysql')
		{
			$connection->queryExecute("
				INSERT IGNORE INTO b_call_conference_user_role (
					CONFERENCE_ID, USER_ID, ROLE
				)
				SELECT 
					CONFERENCE_ID, USER_ID, ROLE
				FROM b_im_conference_user_role
				WHERE 
					CONFERENCE_ID IN (SELECT ID FROM b_call_conference)
			");
		}
		elseif ($connection->getType() == 'pgsql')
		{
			// language=PostgreSQL
			$connection->queryExecute("
				INSERT INTO b_call_conference_user_role (
					conference_id, user_id, role
				)
				SELECT
					conference_id, user_id, role
				FROM b_im_conference_user_role
				WHERE 
					conference_id IN (SELECT id FROM b_call_conference)
				ON CONFLICT (conference_id, user_id) DO NOTHING
			");
		}
		$this->setCurrentStep(5);
	}

	private function getPreviousStep(): int
	{
		return (int)Option::get('call', 'call_db_migrated', 0);
	}

	private function setCurrentStep(int $step): void
	{
		Option::set('call', 'call_db_migrated', $step);
	}
}