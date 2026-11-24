<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Public\Features\MessageSearch;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Search\Access\CodeCarrierResolver;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\UpdateFeature;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;

/**
 * Handlers of the foreign events the cross-search of messages subscribes to: the access code of the
 * open contexts on the search path, the change of the visibility flag on the settings path and the
 * rebuild of the general index of the portal.
 *
 * The access code chain runs on every search request of the portal, of any module, so both its
 * branches stay cheap: the flags come from the process-wide option cache and the answer of the
 * predicate is memoized for the process.
 */
final class EventHandler
{
	/** Name of the flag in the feature set the clients keep. */
	private const VISIBILITY_FEATURE_NAME = 'messageSearchAvailable';

	private const REINDEX_LOG_TAG = 'message-search-reindex';

	/** The rebuild borrows the portion of the archive walk: the same records read by the same query. */
	private const REINDEX_PORTION_OPTION = 'message_search_backfill_portion';
	private const DEFAULT_REINDEX_PORTION = 500;

	/** @var array<int, bool> */
	private static array $carriers = [];

	/**
	 * @return string[]|null Anything but an array leaves the codes of the user untouched.
	 */
	public static function onSearchCheckPermissions(): ?array
	{
		if (!Features::isMessageSearchIndexingEnabled() && !Features::isMessageSearchAvailable())
		{
			return null;
		}

		// a null user is neither extranet, guest nor collaber, so the absence of a user is spelled out here
		$userId = Locator::getContext()->getUserId();
		if ($userId <= 0 || !self::isCarrier($userId))
		{
			return null;
		}

		return [PermissionBuilder::OPEN_ACCESS_CODE];
	}

	/**
	 * Carries the visibility flag to the mobile clients over the socket. There is no human sender here:
	 * main raises the event on any Option::set. A repeated value is sent as well - the clients are
	 * idempotent, while a missed change would leave the section standing until the next load.
	 */
	public static function onVisibilityOptionChanged(Event $event): void
	{
		$value = $event->getParameter('value');
		if (!in_array($value, ['Y', 'N'], true))
		{
			return;
		}

		if (FeatureFlagTable::getByPrimary(MessageSearch::class)->fetch() !== false)
		{
			return;
		}

		(new UpdateFeature(self::VISIBILITY_FEATURE_NAME, $value === 'Y'))->send();
	}

	/**
	 * Restores the messages of the module when the general index of the portal is rebuilt: the core
	 * erases everything and asks every subscriber to hand its records back.
	 *
	 * The walk goes on inside the call and ends on the answer of the callback. The core asks a second
	 * time only while the duration of the step of the search module is set; with it cleared, a handler
	 * that hands one portion over and waits to be asked again would silently leave the rest of the
	 * archive out. The portion is the unit of reading and not of the call.
	 *
	 * Composition, rights and hygiene are those of the online pipeline. Writing is the business of the
	 * core: the record goes into its callback, which stops the walk when the time of the step is spent.
	 *
	 * With the pipeline switched off the module hands nothing back and says so in the log: the entries
	 * of im are already deleted, so a full run of the archive stepper afterwards is a requirement.
	 *
	 * @param array $NS State of the continuation.
	 * @param object $callbackObject Callback object of the core.
	 * @param string $callbackMethod Name of its method.
	 * @return int|false Id of the last record handled, to continue from; false when the walk is over.
	 */
	public static function onReindex($NS, $callbackObject, $callbackMethod)
	{
		if (!Features::isMessageSearchIndexingEnabled())
		{
			(new Logger(self::REINDEX_LOG_TAG))->log(
				'a rebuild of the general index while the pipeline is off: the entries of im are deleted,'
				. ' a full run of the archive stepper is required once the flag is switched back on'
			);

			return false;
		}

		// the records go to the core, which rewrites an entry already standing in the index through
		// CharToDateFunction() - it reads the date it is given as the local time of whoever started
		// the rebuild, so the whole walk runs with the time zones off, exactly as the adapter writes
		\CTimeZone::Disable();

		try
		{
			return self::walkArchive(self::resolveReindexCursor($NS), $callbackObject, $callbackMethod);
		}
		finally
		{
			\CTimeZone::Enable();
		}
	}

	/**
	 * @param object $callbackObject Callback object of the core.
	 * @param string $callbackMethod Name of its method.
	 * @return int|false Id of the last record handled, to continue from; false when the walk is over.
	 */
	private static function walkArchive(int $cursor, $callbackObject, $callbackMethod)
	{
		$contentBuilder = ServiceLocator::getInstance()->get(ContentBuilder::class);
		$hygieneRegistry = ServiceLocator::getInstance()->get(HygieneRegistry::class);

		while (true)
		{
			$messages = self::readReindexPortion($cursor);
			if ($messages->count() === 0)
			{
				return false;
			}

			$portionStart = $cursor;
			$portion = new WalkPortion();

			try
			{
				$portion->addMessages($messages);
				$portion->addChatsOf($messages);

				foreach ($messages as $message)
				{
					$messageId = $message->getMessageId() ?? 0;
					if ($messageId <= 0)
					{
						continue;
					}

					// the cursor follows the walk and not the records handed over: a message the registry keeps
					// out of the index is passed by, and the walk has to go on below it all the same
					$cursor = $messageId;

					$chat = Chat::getInstance($message->getChatId());
					$portion->addChat($chat);

					if (!$hygieneRegistry->isIndexable($message, $chat))
					{
						continue;
					}

					$entry = $contentBuilder->build($message, $chat);
					if ($entry === [] || $entry['PERMISSIONS'] === [])
					{
						// a record no code reaches is findable by nobody, and the walk goes on below it
						continue;
					}

					$entry['ID'] = $messageId;
					if (!call_user_func([$callbackObject, $callbackMethod], $entry))
					{
						return $cursor;
					}
				}
			}
			finally
			{
				$portion->release();
			}

			// a portion the cursor could not move below would be read again and again
			if ($cursor === $portionStart)
			{
				return false;
			}

			// the duration of the step is checked by the callback of the core, and the callback is
			// reached by the records handed over alone: a run of portions the rule keeps out of the
			// index - system messages, expiring ones, the chats of a switched off feature - never
			// asks it anything and would hold the worker over the whole archive below them
			if (self::isStepTimeSpent($callbackObject))
			{
				return $cursor;
			}
		}
	}

	/**
	 * The very condition of the callback of the core, asked where the callback itself is not reached.
	 * A duration that is not set means a rebuild the core walks in one call and asks no continuation
	 * of, so the walk goes on to the end of the archive exactly as it does today.
	 *
	 * @param object $callbackObject Callback object of the core.
	 */
	private static function isStepTimeSpent($callbackObject): bool
	{
		$limit = (int)($callbackObject->max_execution_time ?? 0);
		if ($limit <= 0)
		{
			return false;
		}

		$startTime = property_exists($callbackObject, 'startTime')
			? (float)$callbackObject::$startTime
			: (float)START_EXEC_TIME
		;

		return microtime(true) - $startTime > $limit;
	}

	private static function isCarrier(int $userId): bool
	{
		return self::$carriers[$userId] ??= (new CodeCarrierResolver())->isCarrier($userId);
	}

	/**
	 * @return int Message the walk starts below; zero for the first call, which starts at the top of
	 *             the archive and needs no upper bound at all.
	 */
	private static function resolveReindexCursor(array $NS): int
	{
		if (($NS['MODULE'] ?? '') !== 'im')
		{
			return 0;
		}

		return max(0, (int)($NS['ID'] ?? 0));
	}

	/**
	 * The portion as a collection: its parameters are then filled by a single query for the whole
	 * portion, while a message built on its own loads them one by one - and both the hygiene registry
	 * and the builder of the entry ask for them.
	 */
	private static function readReindexPortion(int $cursor): MessageCollection
	{
		$query = MessageTable::query()
			->setSelect(['*'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(self::getReindexPortion())
		;

		if ($cursor > 0)
		{
			$query->where('ID', '<', $cursor);
		}

		$messages = new MessageCollection($query->fetchAll());
		$messages->fillParams();

		return $messages;
	}

	private static function getReindexPortion(): int
	{
		return max(1, (int)Option::get('im', self::REINDEX_PORTION_OPTION, self::DEFAULT_REINDEX_PORTION));
	}
}
