<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Main;
use Bitrix\Mail;

class MessageIndexStepper extends Main\Update\Stepper
{
	const INDEX_VERSION = 3;
	private const LIMIT = 1000;
	private const PHASE_INITIAL = 'initial';
	private const PHASE_CATCH_UP = 'catchUp';

	protected static $moduleId = 'mail';

	public static function execAgent()
	{
		if (Main\Config\Option::get('mail', 'history_backfill_stopped') !== 'N')
		{
			return '';
		}

		return parent::execAgent();
	}

	public function execute(array &$option)
	{
		if (Main\Config\Option::get('mail', 'history_backfill_stopped') !== 'N')
		{
			// Keep the cursor: FINISH_EXECUTION would delete the saved progress.
			return self::CONTINUE_EXECUTION;
		}

		if (!isset($option['lastId'], $option['maxId'], $option['phase']))
		{
			$maxId = Mail\MailMessageTable::getList(array(
				'select' => array('ID'),
				'filter' => array('<INDEX_VERSION' => static::INDEX_VERSION),
				'order' => array('ID' => 'DESC'),
				'limit' => 1,
			))->fetch();

			$option = array(
				'lastId' => 0,
				'maxId' => (int)($maxId['ID'] ?? 0),
				'phase' => self::PHASE_INITIAL,
				'steps' => 0,
				'count' => Mail\MailMessageTable::getCount(array(
					'<INDEX_VERSION' => static::INDEX_VERSION,
				)),
			);
		}

		if ($option['phase'] === self::PHASE_INITIAL && (int)$option['lastId'] >= (int)$option['maxId'])
		{
			$this->startCatchUp($option);
		}

		$filter = array(
			'<INDEX_VERSION' => static::INDEX_VERSION,
			'>ID' => (int)$option['lastId'],
		);
		if ($option['phase'] === self::PHASE_INITIAL)
		{
			$filter['<=ID'] = (int)$option['maxId'];
		}

		$res = Mail\MailMessageTable::getList(array(
			'select' => array(
				'ID',
				'FIELD_FROM', 'FIELD_REPLY_TO',
				'FIELD_TO', 'FIELD_CC', 'FIELD_BCC',
				'SUBJECT', 'BODY', 'HEADER',
			),
			'filter' => $filter,
			'order' => array('ID' => 'ASC'),
			'limit' => self::LIMIT,
		));

		$processed = 0;
		while ($item = $res->fetch())
		{
			$processed++;
			$option['steps']++;
			$option['lastId'] = $item['ID'];
			$originalRecipients = '';
			$parsedHeader = null;
			if ((string)$item['HEADER'] !== '')
			{
				$parsedHeader = \CMailMessage::parseHeader((string)$item['HEADER'], LANG_CHARSET);
				$originalRecipients = Message::getOriginalRecipientsFromParsedHeader($parsedHeader);
			}
			if ($originalRecipients !== '')
			{
				$item['FIELD_BCC'] = Message::getBccFromParsedHeader($parsedHeader);
			}

			$fields = array(
				'SEARCH_CONTENT' => Message::prepareSearchContent($item, $originalRecipients),
				'INDEX_VERSION' => static::INDEX_VERSION,
			);
			if ($originalRecipients !== '')
			{
				$fields['FIELD_BCC'] = $item['FIELD_BCC'];
			}

			Mail\MailMessageTable::update($item['ID'], $fields);
		}

		$option['count'] = max((int)$option['count'], (int)$option['steps']);
		if ($processed > 0)
		{
			return self::CONTINUE_EXECUTION;
		}

		if ($option['phase'] === self::PHASE_INITIAL)
		{
			$this->startCatchUp($option);

			return self::CONTINUE_EXECUTION;
		}

		return self::FINISH_EXECUTION;
	}

	private function startCatchUp(array &$option): void
	{
		$option['phase'] = self::PHASE_CATCH_UP;
		$option['lastId'] = 0;
	}

}
