<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Update\Stepper;

Loc::loadMessages(__FILE__);

/**
 * Grants the shared access code to the entries the portal collected before that code existed: open
 * channels, open chats, open collabs and the general chats of the portal.
 *
 * One command per context and no rereading of texts: rights are additive, so the member names already
 * baked into those entries stay, and they are what keeps the extranet participants findable.
 *
 * The portion is counted in chats and kept small on purpose: behind every command the engine walks
 * every entry of that context through its shared queue.
 */
final class OpenContextPermissionStepper extends Stepper
{
	private const PERIOD_OPTION = 'message_search_open_perm_period';
	private const PORTION_OPTION = 'message_search_open_perm_portion';
	private const DEFAULT_PERIOD = 120;
	private const DEFAULT_PORTION = 50;

	protected static $moduleId = 'im';

	public static function getTitle()
	{
		return Loc::getMessage('IM_SEARCH_OPEN_PERM_STEPPER_TITLE');
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
			// and the cursor of the walk lives in that state - a rollback of the flag would then cost
			// every context covered so far
			return self::CONTINUE_EXECUTION;
		}

		if (!isset($option['cursor']))
		{
			$option['cursor'] = 0;
			$option['processed'] = 0;
			$option['count'] = $this->countOpenContexts();
		}

		$chatIds = $this->readPortion((int)$option['cursor']);
		if ($chatIds === [])
		{
			return self::FINISH_EXECUTION;
		}

		$this->grantSharedCode($chatIds);

		$option['cursor'] = end($chatIds);
		$option['processed'] = (int)($option['processed'] ?? 0) + count($chatIds);
		$option['steps'] = $option['processed'];

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * The cursor of this walk moves in one direction and never comes back, so a context the index
	 * refused is handed over to the queue of the domain instead of being left behind: without the shared
	 * code its archive stays out of reach of the employees the open type gives access to.
	 *
	 * An open type answered by a parent it needs membership in is no context of the portal at all: the
	 * walk takes the shared code off such a chat instead of handing it out and queues the names of its
	 * members. The taking off is what an index collected before this rule covers - an entry written by
	 * the pipeline as it stands never carried the code.
	 *
	 * @param int[] $chatIds
	 */
	private function grantSharedCode(array $chatIds): void
	{
		$synchronizer = ServiceLocator::getInstance()->get(OpenCodeSynchronizer::class);
		$isHandedOver = false;

		foreach ($chatIds as $chatId)
		{
			$isHandedOver = $synchronizer->sync($chatId) || $isHandedOver;
		}

		if ($isHandedOver)
		{
			AgentRegistrar::registerChatConversionRebake();
		}
	}

	/**
	 * @return int[] Chats of the portion in the order of the walk.
	 */
	private function readPortion(int $cursor): array
	{
		$rows = $this->buildOpenContextQuery()
			->setSelect(['ID'])
			->where('ID', '>', $cursor)
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->getPortion())
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Counted once, at the start: the whole of the indicator, and the figure the volume of the
	 * operation is measured by before it is raised on a portal.
	 */
	private function countOpenContexts(): int
	{
		return (int)$this->buildOpenContextQuery()->setSelect(['ID'])->queryCountTotal();
	}

	/**
	 * Openness is asked of the registry of types and never spelled out here: an open collab and the
	 * general chat of the portal are open by their own rows of that registry, and a list written down
	 * in this class would go stale the moment a type is added.
	 */
	private function buildOpenContextQuery(): Query
	{
		$condition = ServiceLocator::getInstance()->get(TypeRegistry::class)->getOpenTypeCondition();

		return ChatTable::query()->where((new TypeFilter($condition, 'TYPE', 'ENTITY_TYPE'))->toConditionTree());
	}

	private function getPortion(): int
	{
		return max(1, (int)Option::get(self::$moduleId, self::PORTION_OPTION, self::DEFAULT_PORTION));
	}
}
