<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Eligibility;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Which chats a personal folder may hold. The rule is computed, never enumerated:
 * the 'default' recent section plus the recent sections of the potential composition of "All"
 * ({@see AllFolder::getPotentialRecentSections()}), so a source added to the canonical registry
 * of "All" earns the right on its own.
 *
 * Lives in the folder domain rather than in {@see TypeRegistry}: "what may go into a folder" is
 * a property of the folder, while the type machinery is reused as is.
 *
 * The rule works on recent sections, i.e. on the extended type of a chat, not on an enumeration
 * of literal+entityType pairs: the AI assistant dialog (P + AI_ASSISTANT_PRIVATE) is not a
 * registered pair at all, and a pair check would reject it.
 *
 * Every input is portal-wide and already resolved on the same page (feature gates are memoized,
 * {@see TypeRegistry} and {@see RecentConfigManager} are singletons whose maps are built without
 * touching the database), so the rule is recomputed on demand instead of being cached - a cache
 * here would only add invalidation. Checking a whole set of chats at once is the one case where the
 * recomputation is plainly wasted, and {@see self::withRecentSectionsResolvedOnce()} covers it
 * without leaving anything behind the batch.
 */
final class EligibleChatTypes
{
	/** @var string[]|null recent sections held for the span of one batch check, null outside it */
	private static ?array $batchRecentSections = null;

	public static function isAllowed(Chat $chat): bool
	{
		return array_intersect($chat->getRecentSections(), self::getRecentSections()) !== [];
	}

	/**
	 * Runs $check with the recent sections resolved once instead of once per chat: checking a batch
	 * of chats otherwise rebuilds the registry of system folders for every one of them. The list is
	 * portal-wide and cannot change while the batch is being checked, and it is released as soon as
	 * $check returns, so nothing of it outlives the batch.
	 *
	 * @template T
	 * @param callable(): T $check
	 * @return T
	 */
	public static function withRecentSectionsResolvedOnce(callable $check): mixed
	{
		if (self::$batchRecentSections !== null)
		{
			return $check();
		}

		self::$batchRecentSections = self::computeRecentSections();

		try
		{
			return $check();
		}
		finally
		{
			self::$batchRecentSections = null;
		}
	}

	/**
	 * The same rule as a condition for queries. Sections are combined as "include is a union,
	 * exclude is an intersection": {@see TypeCondition::merge()} unions the exclusions instead and
	 * subtracts them from the include, which would drop the visitor side of an open line
	 * (C + LIVECHAT) from an otherwise allowed set.
	 */
	public static function getTypeCondition(): TypeCondition
	{
		$registry = ServiceLocator::getInstance()->get(TypeRegistry::class);
		$include = [];
		$excludeSets = [];

		foreach (self::getRecentSections() as $section)
		{
			$condition = $registry->getConditionByRecentSection($section);
			$include = array_merge($include, $condition->include ?? []);
			$excludeSets[] = $condition->exclude;
		}

		return new TypeCondition(include: $include, exclude: self::intersectTypes($excludeSets));
	}

	/**
	 * @return string[]
	 */
	private static function getRecentSections(): array
	{
		return self::$batchRecentSections ?? self::computeRecentSections();
	}

	/**
	 * @return string[]
	 */
	private static function computeRecentSections(): array
	{
		return array_values(array_unique(array_merge(
			[RecentConfigManager::DEFAULT_SECTION_NAME],
			AllFolder::getPotentialRecentSections(),
		)));
	}

	/**
	 * @param Type[][] $excludeSets
	 * @return Type[]
	 */
	private static function intersectTypes(array $excludeSets): array
	{
		$intersection = null;

		foreach ($excludeSets as $types)
		{
			$keyed = [];
			foreach ($types as $type)
			{
				$keyed[$type->getFilterKey()] = $type;
			}

			$intersection = $intersection === null ? $keyed : array_intersect_key($intersection, $keyed);
			if ($intersection === [])
			{
				break;
			}
		}

		return array_values($intersection ?? []);
	}
}
