<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Integration\Search\HygieneRegistry;
use Bitrix\Im\V2\Integration\Search\SearchAdapter;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Takes out of the index the entries the product registry does not index at all.
 *
 * An archive collected by foreign code passed two filters only - system messages and notifications -
 * so classes the registry keeps out are sitting in it: hidden and service messages of the Open Lines,
 * drafts of Copilot, disappearing messages, softly deleted ones, service cards of the messenger. The
 * facade does not check the hygiene of what it reads.
 *
 * A cleanup and not a reindexing: an entry that passes the registry is not rewritten, so texts, rights
 * and DATE_CHANGE stay untouched and the queue of the rights gets no work.
 *
 * Only entries of im are ever read and removed; the filter that guarantees it lives in the adapter.
 */
final class HygieneCleanupStepper extends Stepper
{
	/**
	 * Completion mark of the cleanup: the date, the number of entries looked at and the number taken
	 * out. The last is the evidence of the acceptance, so it may not be lost with the state of the
	 * stepper the core deletes as soon as the walk is over.
	 */
	public const COMPLETED_OPTION = 'message_search_hygiene_completed';

	private const PERIOD_OPTION = 'message_search_hygiene_period';
	private const PORTION_OPTION = 'message_search_hygiene_portion';
	private const DEFAULT_PERIOD = 60;
	private const DEFAULT_PORTION = 1000;
	private const LOG_TAG = 'message-search-hygiene';

	protected static $moduleId = 'im';

	public static function getTitle()
	{
		return Loc::getMessage('IM_SEARCH_HYGIENE_STEPPER_TITLE');
	}

	public static function getPeriod(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PERIOD_OPTION, self::DEFAULT_PERIOD));
	}

	/**
	 * @inheritdoc
	 */
	public function execute(array &$option)
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			// the walk stands still but does not end: ending it would make the core delete the state,
			// and the cursor of the walk lives in that state
			return self::CONTINUE_EXECUTION;
		}

		$adapter = ServiceLocator::getInstance()->get(SearchAdapter::class);
		$rows = $adapter->listOwnRecords((string)($option['lastItemId'] ?? ''), $this->getPortion());

		if ($rows === null)
		{
			// unreadable is not exhausted: reporting the cleanup finished here would hand the acceptance
			// a number of removed entries that describes half a walk
			$this->getLogger()->log('hygiene: the index cannot be read, the portion is retried on the next step');

			return self::CONTINUE_EXECUTION;
		}

		if ($rows === [])
		{
			$this->finish($option);

			return self::FINISH_EXECUTION;
		}

		$doomed = ServiceLocator::getInstance()->get(HygieneRegistry::class)
			->nonIndexableOf(array_column($rows, 'messageId'))
		;
		if ($doomed !== [] && !$adapter->deleteByMessageIds($doomed))
		{
			// a refused deletion is not a walked portion: moving the cursor past it would leave the
			// forbidden entries in the index for good and report a number of removals nobody made
			$this->getLogger()->log(
				'hygiene: the index refused a deletion of ' . count($doomed) . ' entries, the portion is retried'
			);

			return self::CONTINUE_EXECUTION;
		}

		// the portion comes in the order of the walk, so its last entry is where the next one starts
		$option['lastItemId'] = (string)end($rows)['itemId'];
		$option['scanned'] = (int)($option['scanned'] ?? 0) + count($rows);
		$option['removed'] = (int)($option['removed'] ?? 0) + count($doomed);
		$option['steps'] = $option['scanned'];

		return self::CONTINUE_EXECUTION;
	}

	private function finish(array &$option): void
	{
		Option::set(self::$moduleId, self::COMPLETED_OPTION, Json::encode([
			'date' => (new DateTime())->format('Y-m-d H:i:s'),
			'scanned' => (int)($option['scanned'] ?? 0),
			'removed' => (int)($option['removed'] ?? 0),
		]));
	}

	private function getPortion(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PORTION_OPTION, self::DEFAULT_PORTION));
	}

	private function getLogger(): Logger
	{
		return new Logger(self::LOG_TAG);
	}
}
