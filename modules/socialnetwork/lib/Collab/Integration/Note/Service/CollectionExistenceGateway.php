<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Note\Service;

use Bitrix\Main\Loader;
use Bitrix\Note\Public\Provider\CollectionProvider;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

/**
 * Single point of the question "does the note collection still exist?".
 *
 * A binding row in b_sonet_collab_note outlives the collection it points to, so
 * the id alone proves nothing. Answers are memoized for as long as the instance
 * lives: the gateway is resolved through the ServiceLocator, so that is one HTTP
 * request - but a queue worker handles a whole batch in a single process, and a
 * collection deleted between two messages of that batch would keep looking alive.
 * Such consumers drop the memo themselves ({@see self::reset()}).
 *
 * The gateway answers about data integrity, not about access: current user
 * permissions are deliberately not taken into account. An archived collection
 * counts as existing.
 */
class CollectionExistenceGateway
{
	/** @var array<int, bool> */
	private array $existenceMemo = [];

	/**
	 * @param CollectionProvider|null $collectionProvider note-side provider; not injected by
	 *   autowiring (module note may be unavailable when the gateway is built) - resolved
	 *   lazily in exists() after includeModule('note').
	 */
	public function __construct(
		private ?CollectionProvider $collectionProvider = null,
	)
	{
	}

	/**
	 * Forgets every memoized answer: a caller that outlives a single request starts each
	 * unit of work with the question unanswered.
	 */
	public function reset(): void
	{
		$this->existenceMemo = [];
	}

	/**
	 * @return bool|null true/false when the answer is known, null when it could not be
	 *   obtained. null never stands for "does not exist": a wrong false would drop a live
	 *   binding and spawn a duplicate knowledge base.
	 */
	public function exists(int $collectionId): ?bool
	{
		if ($collectionId <= 0)
		{
			return false;
		}

		if (isset($this->existenceMemo[$collectionId]))
		{
			return $this->existenceMemo[$collectionId];
		}

		// Self-defence: every consumer checks the module earlier, so with a live note
		// module null means exactly one thing - the provider threw.
		if (!Loader::includeModule('note'))
		{
			return null;
		}

		try
		{
			$this->collectionProvider ??= Container::getInstance()->get(CollectionProvider::class);

			$found = $this->collectionProvider->getById($collectionId) !== null;
		}
		catch (\Throwable)
		{
			return null;
		}

		$this->existenceMemo[$collectionId] = $found;

		return $found;
	}
}
