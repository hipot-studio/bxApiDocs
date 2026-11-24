<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Keeps the journal of refused guests readable and bounded.
 *
 * A public link is meant to be passed around, so the same broken report is opened by guest after guest,
 * and every one of them is refused for the very same reason. Without a window each of those refusals
 * becomes a row of b_event_log, and repeated visits alone are enough to push the rest of the journal of
 * the portal out. One entry per window keeps the stream visible.
 *
 * A refusal folded into an open window writes nothing: the storage is touched only when a window opens, so
 * a stream of refused guests costs the storage no more than it costs the journal.
 *
 * A window belongs to one link: an instance that is not answering refuses every public link of the portal
 * at once, and a window shared by all of them would leave the journal naming whichever link was opened
 * first. The number of windows therefore grows with the links employees have published, never with the
 * number of guests visiting them, and ENTRIES_PER_WINDOW caps it from above.
 *
 * The window matches the one the guest path raises the instance by (@see GuestInstanceWakeupService):
 * nothing on that path can change more often than the instance itself is asked to come up, so a shorter
 * window would only repeat the same reason.
 */
final class GuestRefusalJournalThrottle
{
	public const WINDOW_SECONDS = GuestInstanceWakeupService::WINDOW_SECONDS;

	/**
	 * How many links one window of one reason may name. A portal can hold any number of published links,
	 * and an incident refuses all of them together, so without a ceiling the journal would grow with that
	 * number. The refusals that find no place left share a single entry, the way every refusal of a reason
	 * did before windows were given to links.
	 *
	 * The ceiling is what an incident costs the journal: ENTRIES_PER_WINDOW + 1 entries per window per
	 * reason, that is 6 rows a minute and 360 rows an hour while the reason keeps refusing guests. Five
	 * named links cover a portal that publishes a handful of them, and the portals that publish more are
	 * answered about by the shared entry, which reports the size of the stream without naming anyone.
	 */
	public const ENTRIES_PER_WINDOW = 5;

	private const KEY_PREFIX = 'biconnector.share.guest_refusal_journal.';

	private const BUDGET_KEY_PREFIX = 'biconnector.share.guest_refusal_budget.';

	private const LOCK_KEY_PREFIX = 'biconnector_share_refusal_journal_';

	/**
	 * The work done under the lock is a few short queries, so a refusal can afford to wait for it: without
	 * the wait the first refusal of a link would be lost whenever another link of the same reason holds it.
	 */
	private const LOCK_TIMEOUT_SECONDS = 1;

	private readonly StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * Asks for an entry of the journal for a refusal.
	 *
	 * The windows of a reason are read and opened under a lock of that reason, so refusals arriving together
	 * cannot all find the same window closed and the cap of ENTRIES_PER_WINDOW holds for them as well.
	 * A refusal that does not get the lock within LOCK_TIMEOUT_SECONDS is folded rather than kept waiting.
	 *
	 * @param string $reason What the refusal is counted by; refusals of different reasons never fold
	 *                       into one another.
	 * @param string $link The link the refused guest came by; each link is counted on its own until the
	 *                     entries of the window run out.
	 * @return GuestRefusalJournalEntry|null The entry to write, or null when this refusal itself is folded
	 *                                       into the entry of the open window. An entry given out after the
	 *                                       links of the window ran out stands for a group of links and
	 *                                       names none of them.
	 */
	public function openEntry(string $reason, string $link): ?GuestRefusalJournalEntry
	{
		$connection = Application::getConnection();
		$lockKey = self::LOCK_KEY_PREFIX . $reason;
		if (!$connection->lock($lockKey, self::LOCK_TIMEOUT_SECONDS))
		{
			return null;
		}

		try
		{
			return $this->openEntryOfLink($reason, $link);
		}
		finally
		{
			$connection->unlock($lockKey);
		}
	}

	private function openEntryOfLink(string $reason, string $link): ?GuestRefusalJournalEntry
	{
		$key = self::KEY_PREFIX . $reason . '.' . $link;

		if ($this->isWindowOpen($this->read($key)))
		{
			return null;
		}

		if (!$this->takeEntryOfWindow($reason))
		{
			return $this->openSharedEntry($reason);
		}

		$this->store($key, ['openedAt' => time()]);

		return GuestRefusalJournalEntry::ofLink();
	}

	/**
	 * The entry every link that did not get one of its own is refused into. It stands for all of them at
	 * once, so a portal with more published links than a window can hold still costs the journal a bounded
	 * number of rows. The entry is marked as naming no link: the refusals folded into it come from links
	 * the caller cannot tell apart.
	 */
	private function openSharedEntry(string $reason): ?GuestRefusalJournalEntry
	{
		$key = self::KEY_PREFIX . $reason;

		if ($this->isWindowOpen($this->read($key)))
		{
			return null;
		}

		$this->store($key, ['openedAt' => time()]);

		return GuestRefusalJournalEntry::ofUnnamedLinks();
	}

	/**
	 * Takes one of the entries the open window of a reason still has left.
	 */
	private function takeEntryOfWindow(string $reason): bool
	{
		$key = self::BUDGET_KEY_PREFIX . $reason;
		$record = $this->read($key);

		if (!$this->isWindowOpen($record))
		{
			$this->store($key, ['openedAt' => time(), 'taken' => 1]);

			return true;
		}

		$taken = (int)($record['taken'] ?? 0);
		if ($taken >= self::ENTRIES_PER_WINDOW)
		{
			return false;
		}

		$this->store($key, ['openedAt' => (int)$record['openedAt'], 'taken' => $taken + 1]);

		return true;
	}

	private function isWindowOpen(array $record): bool
	{
		$openedAt = (int)($record['openedAt'] ?? 0);

		return $openedAt > 0 && (time() - $openedAt) <= self::WINDOW_SECONDS;
	}

	/**
	 * A storage that is not answering is not a reason to lose a refusal: the window is what the journal
	 * is kept short by, and without it the entries are simply written as they were before.
	 */
	private function read(string $key): array
	{
		// Read from the primary the lock is taken on: a lagging replica may miss the window just opened.
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			$record = $this->storage->get($key);
		}
		catch (\Throwable)
		{
			return [];
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		return is_array($record) ? $record : [];
	}

	private function store(string $key, array $record): void
	{
		try
		{
			$this->storage->set($key, $record, self::WINDOW_SECONDS);
		}
		catch (\Throwable)
		{
			// See read(): a window that cannot be stored leaves the journal as verbose as it used to be,
			// and that is still better than losing the request of the guest over it.
		}
	}
}
