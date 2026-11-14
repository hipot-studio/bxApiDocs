<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Communications\CommunicationRecord;
use Bitrix\Crm\V2\Internal\Service\Summary\DealSelector;
use Bitrix\Crm\V2\Internal\Service\Summary\SummaryLimits;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * The history of the conversations of one CRM item: what the passed user may hear about a contact, a
 * company or a deal.
 *
 * A conversation is a call, a letter or a dialogue of an open line - the three kinds the speech
 * analysis of CRM writes a summary of. What the history tells about one of them is that summary and
 * never the words that were said.
 *
 * The user is always an argument, the same one all the way down: the deals are selected by their
 * rights and the client of every conversation is named by their rights too. The service never asks the
 * environment who is calling, because it answers an agent acting on behalf of someone else.
 *
 * A client is told about together with its deals, a deal only about itself - the conversations of the
 * other deals of the same client are no part of the history of this one.
 *
 * The ceilings of the number of conversations are applied here: one per source before the merge, one
 * on the answer. The first is never asked for above the second - a source read deeper than the answer
 * carries brings back rows the merge throws away.
 *
 * @internal
 */
final class CommunicationHistoryService
{
	public const ERROR_UNSUPPORTED_ENTITY_TYPE = 'COMMUNICATIONS_UNSUPPORTED_ENTITY_TYPE';

	/**
	 * The one refusal of an item that cannot be answered about. An item the user may not read and an
	 * item that is not there are refused by it alike: told apart, the pair says to whoever may not see
	 * an item that an item of that identifier exists, and an identifier is guessed by counting.
	 */
	public const ERROR_NOT_AVAILABLE = 'COMMUNICATIONS_NOT_AVAILABLE';

	private const SUPPORTED_ENTITY_TYPES = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
		CCrmOwnerType::Deal,
	];

	private readonly DealSelector $dealSelector;
	private readonly CommunicationActivityReader $reader;
	private readonly CommunicationRecordAssembler $assembler;
	private readonly RecurringDealReader $recurringDeals;

	public function __construct(
		?DealSelector $dealSelector = null,
		?CommunicationActivityReader $reader = null,
		?CommunicationRecordAssembler $assembler = null,
		?RecurringDealReader $recurringDeals = null,
	)
	{
		$this->dealSelector = $dealSelector ?? new DealSelector();
		$this->reader = $reader ?? new CommunicationActivityReader();
		$this->assembler = $assembler ?? new CommunicationRecordAssembler();
		$this->recurringDeals = $recurringDeals ?? new RecurringDealReader();
	}

	/**
	 * @param int|null $limit Conversations the answer carries; the default of
	 * {@see CommunicationHistoryLimits} when the caller names no number, and cut down to the ceiling
	 * when the number is above it.
	 * @return Result Success carries `['items' => CommunicationRecord[], 'truncationReason' =>
	 * TruncationReason|null]`, the freshest conversation first. The reason is what the answer is short
	 * by, and null says the list is the whole history of the item.
	 */
	public function read(int $entityTypeId, int $entityId, int $userId, ?int $limit = null): Result
	{
		$result = new Result();
		if (!in_array($entityTypeId, self::SUPPORTED_ENTITY_TYPES, true))
		{
			return $result->addError(new Error(
				'Only a contact, a company or a deal has a history of conversations',
				self::ERROR_UNSUPPORTED_ENTITY_TYPE,
			));
		}

		if (!Container::getInstance()->getUserPermissions($userId)->item()->canRead($entityTypeId, $entityId))
		{
			return $result->addError(new Error(
				'The conversations of the item are not available to the user',
				self::ERROR_NOT_AVAILABLE,
			));
		}

		if ($this->isRecurringDeal($entityTypeId, $entityId))
		{
			return $result->addError(new Error(
				'A recurring deal is a template of the deals to come and has no history of its own',
				self::ERROR_NOT_AVAILABLE,
			));
		}

		$item = new ItemIdentifier($entityTypeId, $entityId);
		$itemsLimit = self::itemsLimitOf($limit);
		$conversations = $this->reader->read(
			$this->sourcesOf($item, $userId),
			min(CommunicationHistoryLimits::ITEMS_PER_SOURCE, $itemsLimit),
			$itemsLimit,
		);

		return $result->setData([
			'items' => $this->assembler->assemble($conversations->items, $userId),
			'truncationReason' => $conversations->truncationReason,
		]);
	}

	/**
	 * A deal that is a template of the deals to come rather than a deal that happened is refused the way
	 * the summary of a single deal refuses it - but by the one refusal of this service, so it stays
	 * indistinguishable from a deal the user may not read.
	 *
	 * Asked past the gate of the rights and of a deal alone: the row is read once and only for the item
	 * the caller named, while the deals of a client are dropped by the condition of the selection that
	 * reads them and cost no read of their own.
	 */
	private function isRecurringDeal(int $entityTypeId, int $entityId): bool
	{
		return $entityTypeId === CCrmOwnerType::Deal && $this->recurringDeals->isRecurring($entityId);
	}

	/**
	 * The item itself and, for a client, the deals of it the summary of that client would tell the
	 * details of: the depth of the walk is taken from there and is not deepened here, so a client
	 * answers both tools about the same deals.
	 *
	 * The selector filters the deals by the rights of the user and leaves the recurring ones out - a
	 * template of the deals to come has no conversations of its own. It is asked for exactly the deals
	 * the walk takes: the aggregates of a summary are counted over a hundred deals, and a hundred read
	 * to name five is the price of the biggest clients of a portal.
	 *
	 * @return ItemIdentifier[]
	 */
	private function sourcesOf(ItemIdentifier $item, int $userId): array
	{
		if ($item->getEntityTypeId() === CCrmOwnerType::Deal)
		{
			return [$item];
		}

		if (!Container::getInstance()->getUserPermissions($userId)->entityType()->canReadItems(CCrmOwnerType::Deal))
		{
			return [$item];
		}

		$sources = [$item];
		$dealIds = array_map(
			static fn (Item $deal): int => $deal->getId(),
			$this->dealSelector->selectAccessible($item, $userId, SummaryLimits::DEAL_DETAILS),
		);
		foreach ($dealIds as $dealId)
		{
			$sources[] = new ItemIdentifier(CCrmOwnerType::Deal, $dealId);
		}

		return $sources;
	}

	private static function itemsLimitOf(?int $limit): int
	{
		if ($limit === null || $limit <= 0)
		{
			return CommunicationHistoryLimits::DEFAULT_ITEMS_LIMIT;
		}

		return min($limit, CommunicationHistoryLimits::MAX_ITEMS_LIMIT);
	}
}
