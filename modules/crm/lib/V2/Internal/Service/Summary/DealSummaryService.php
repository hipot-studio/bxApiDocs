<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Summary\SectionState;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummaryResult;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

/**
 * Summary of a deal: what the passed user may read about one deal and about what happened around it.
 *
 * The user is always an argument, the way it is for {@see ClientSummaryService}: the service answers
 * an agent that acts on behalf of someone else and never asks the environment who is calling.
 *
 * The right to read the deal is the gate of the whole assembly and stands before any read. It plays
 * the part the first step of a client summary plays there: that one applies the rights by the query
 * that selects the deals, while here the identifier is a single one and comes from the arguments.
 *
 * The gate covers the deal and what is said around it, and not what the deal points at: the client of
 * it is a CRM entity of its own with rights of its own, so {@see DealClientReader} checks it again, and
 * so are the invoices hanging off it, which {@see DealInvoicesReader} reads under the rights of their
 * own type. What is sold in the deal is closed by a right of the catalog and by no right of CRM at
 * all, which is the one {@see DealProductsReader} asks.
 *
 * A refusal leaves the {@see Result} with an error of a known code and no data.
 *
 * @internal
 */
final class DealSummaryService
{
	public const ERROR_ACCESS_DENIED = 'DEAL_SUMMARY_ACCESS_DENIED';
	public const ERROR_UNSUPPORTED_DEAL = 'DEAL_SUMMARY_UNSUPPORTED_DEAL';

	private const LOGGER_ID = 'Integration.AI';

	private const ENTITY_TYPE_NAME = 'deal';

	/**
	 * Kinds the communications section is laid out by - the strategies {@see CommunicationsReader}
	 * asks, counted one by one for the journal. Whatever else the section carries beside them is no
	 * record of a kind.
	 */
	private const COMMUNICATION_KINDS = [
		ActivityType::CALL_RECORDING_TRANSCRIPTS->value,
		ActivityType::COMMENTS->value,
		ActivityType::TODOS->value,
		ActivityType::EMAILS->value,
	];

	private readonly DealSelector $dealSelector;
	private readonly DealDetailsReader $dealDetailsReader;
	private readonly DealClientReader $dealClientReader;
	private readonly CommunicationsReader $communicationsReader;
	private readonly LastCallSummaryReader $lastCallSummaryReader;
	private readonly DealProductsReader $dealProductsReader;
	private readonly DealInvoicesReader $dealInvoicesReader;
	private readonly DealSignalsReader $dealSignalsReader;
	private readonly LoggerInterface $logger;

	public function __construct(
		?DealSelector $dealSelector = null,
		?DealDetailsReader $dealDetailsReader = null,
		?DealClientReader $dealClientReader = null,
		?CommunicationsReader $communicationsReader = null,
		?LastCallSummaryReader $lastCallSummaryReader = null,
		?DealProductsReader $dealProductsReader = null,
		?DealInvoicesReader $dealInvoicesReader = null,
		?DealSignalsReader $dealSignalsReader = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->dealSelector = $dealSelector ?? new DealSelector();
		$this->dealDetailsReader = $dealDetailsReader ?? new DealDetailsReader();
		$this->dealClientReader = $dealClientReader ?? new DealClientReader();
		$this->communicationsReader = $communicationsReader ?? new CommunicationsReader();
		$this->lastCallSummaryReader = $lastCallSummaryReader ?? new LastCallSummaryReader();
		$this->dealProductsReader = $dealProductsReader ?? new DealProductsReader();
		$this->dealInvoicesReader = $dealInvoicesReader ?? new DealInvoicesReader();
		$this->dealSignalsReader = $dealSignalsReader ?? new DealSignalsReader();
		$this->logger = $logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}

	/**
	 * @return Result Success carries `['summary' => SummaryResult]`.
	 */
	public function collect(int $dealId, int $userId): Result
	{
		$result = new Result();

		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$permissions->item()->canRead(CCrmOwnerType::Deal, $dealId))
		{
			return $result->addError(new Error(
				'The user may not read the deal of the summary',
				self::ERROR_ACCESS_DENIED,
			));
		}

		// the journal is told what the call cost, so the count starts at the first read of it
		$startedAt = microtime(true);

		$deal = self::dealRow($dealId);

		$refusal = self::refuseWhatIsNoDealToSummarize($deal);
		if ($refusal !== null)
		{
			return $result->addError($refusal);
		}

		$details = $this->readDetails($dealId, $deal);
		$client = $this->dealClientReader->read($deal, $userId);
		$communications = $this->readCommunications($dealId);
		$products = $this->dealProductsReader->read($deal, $userId);
		$invoices = $this->dealInvoicesReader->read($deal, $userId);

		$sections = [
			DealSectionCode::Details->value => $details,
			DealSectionCode::Client->value => $client,
			DealSectionCode::Communications->value => $communications,
			DealSectionCode::Products->value => $products->section,
			DealSectionCode::Invoices->value => $invoices,
		];

		$summary = new SummaryResult(self::ENTITY_TYPE_NAME, $dealId, $sections);

		$this->logCall($summary, $userId, $sections, $startedAt, $products->isCatalogRightNotChecked);

		return $result->setData(['summary' => $summary]);
	}

	/**
	 * A deal there is nothing to summarize about, told apart from one the user may not read: a deal
	 * gone from the storage, and a recurring one - a template of the deals to come rather than a deal
	 * that happened. Neither is a refusal of the rights, so both are checked past the gate of them.
	 *
	 * The template is left out here and nowhere else: the condition that drops it lives in the
	 * selection of the deals of a client, and a summary of a single deal has no such selection - it
	 * reads the deal by the identifier it was given.
	 *
	 * The flag arrives as the storage keeps it and not as a boolean: a row fetched into an array carries
	 * the value of the column, and only an objectified read casts a `BooleanField` to `true` or `false`.
	 *
	 * @param array|null $deal The row of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT}, or null when
	 * the storage has no such deal.
	 */
	private static function refuseWhatIsNoDealToSummarize(?array $deal): ?Error
	{
		if ($deal === null)
		{
			return new Error('There is no such deal to summarize', self::ERROR_UNSUPPORTED_DEAL);
		}

		if (($deal[Item::FIELD_NAME_IS_RECURRING] ?? null) === 'Y')
		{
			return new Error(
				'A recurring deal is a template of the deals to come and no subject of a summary',
				self::ERROR_UNSUPPORTED_DEAL,
			);
		}

		return null;
	}

	/**
	 * One row of the primary key: the cheapest read that answers what the gate asks about, what the
	 * details need beyond the map they are built by, and whom the deal is with. They are one read and
	 * not three - the columns of all of them are asked for at once.
	 */
	private static function dealRow(int $dealId): ?array
	{
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if ($factory === null)
		{
			return null;
		}

		$dataClass = $factory->getDataClass();

		return $dataClass::getRow([
			'select' => SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT,
			'filter' => ['=' . Item::FIELD_NAME_ID => $dealId],
		]);
	}

	/**
	 * The details of the deal in the form the section carries: one flat record of the fields of the map,
	 * and not a list of a single record.
	 *
	 * The reader tells about the deals of a client and admits leaving some of them out of the list. Here
	 * the list is one deal long, so the reader is told that one deal is accessible and gives no such
	 * admission back; what it does admit - a text of the deal cut to the limit of a record - travels as
	 * it was named. A deal gone between the gate and this read leaves an empty section, and that travels
	 * as it is too.
	 *
	 * What the fields of the deal add up to - {@see DealSignalsReader} - is told inside this record and
	 * not beside it: those are facts about the deal itself, and a section of their own would split the
	 * state of a deal in two.
	 *
	 * @param array $dealRow The row of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT}.
	 */
	private function readDetails(int $dealId, array $dealRow): SummarySection
	{
		$deals = array_values($this->dealSelector->selectDetails([$dealId]));

		$section = $this->dealDetailsReader->read($deals, 1);
		if ($section->state !== SectionState::Filled)
		{
			return $section;
		}

		$record = self::withTheAssignee($section->data[0], $dealRow);

		return SummarySection::filled(
			array_merge($record, $this->dealSignalsReader->read($deals[0], $dealRow)),
			$section->truncationReason,
		);
	}

	/**
	 * Who the deal is on, told by name: the format of a name belongs to the culture of the portal, so
	 * the broker of the users is the one who says it. Whether this user may be named is not asked - the
	 * right to the deal has been checked by the gate, and a name of a user is closed by no right of CRM.
	 *
	 * A user the portal no longer has is answered `null` by the broker, and the record then carries the
	 * identifier alone: a deal of a dismissed manager and a deal on nobody are not the same thing, and
	 * an empty name would make them one.
	 */
	private static function withTheAssignee(array $record, array $dealRow): array
	{
		$assigneeId = (int)($dealRow[Item::FIELD_NAME_ASSIGNED] ?? 0);

		$record['assignedById'] = $assigneeId > 0 ? $assigneeId : null;
		$record['assignedBy'] = $assigneeId > 0
			? Container::getInstance()->getUserBroker()->getName($assigneeId)
			: null
		;

		return $record;
	}

	/**
	 * What the deal has been talked about, read of the single source there is - the deal itself.
	 *
	 * The order is the one of {@see ClientSummaryService}: the calls are read once and handed to both of
	 * those who need them, the summary of the latest of them is looked for next, and the reader of the
	 * communications is given both. The limits it is asked with are the ones of a deal - a section read
	 * of one source is bounded by numbers of its own.
	 *
	 * The reader is asked to probe for what it left out, and it is asked here and nowhere else: the guard
	 * of the reader compares what a kind gathered with the limit of a kind times the number of the sources,
	 * and a single source never reaches it. Counting the records the section carries instead would call a
	 * deal incomplete for having exactly as many records of a kind as it is allowed - a deal with nothing
	 * more to tell and a deal with more of it are the same when counted.
	 *
	 * The activities are read with the probe record included, so what is handed to the strategies still
	 * covers every record the probe needs: a record above them would cost a read of its own.
	 */
	private function readCommunications(int $dealId): SummarySection
	{
		$sources = [new ItemIdentifier(CCrmOwnerType::Deal, $dealId)];
		$perKind = SummaryLimits::DEAL_COMMUNICATIONS_PER_KIND;

		$activities = $this->lastCallSummaryReader->readActivities(
			$sources,
			$perKind + SummaryLimits::PROBE_RECORD,
		);
		$lastCall = $this->lastCallSummaryReader->read($activities->items);

		return $this->communicationsReader->read(
			$sources,
			$activities,
			$lastCall,
			$perKind,
			SummaryLimits::DEAL_KIND_LENGTH,
			isProbingForMore: true,
		);
	}

	/**
	 * @param array<string, SummarySection> $sections
	 * @param bool $isCatalogRightNotChecked Whether the products section was decided in a state of the
	 * portal where the right of the catalog is not measured for the user it was asked about. The
	 * invariant is held there by a setting of the portal and not by this code, and hiding that from
	 * whoever reads the journal is not an option.
	 */
	private function logCall(
		SummaryResult $summary,
		int $userId,
		array $sections,
		float $startedAt,
		bool $isCatalogRightNotChecked,
	): void
	{
		$this->logger->info(
			'{date}: deal summary collected: entityType={entityType}, entityId={entityId}'
			. ', userId={userId}, durationMs={durationMs}'
			. ', communicationsByKind={communicationsByKind}'
			. ', catalogRightNotChecked={catalogRightNotChecked}'
			. ', truncatedSections={truncatedSections}, emptySections={emptySections}' . PHP_EOL,
			[
				'entityType' => $summary->entityType,
				'entityId' => $summary->entityId,
				'userId' => $userId,
				'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				'communicationsByKind' => self::communicationsByKind($sections),
				'catalogRightNotChecked' => $isCatalogRightNotChecked,
				'truncatedSections' => self::codesOf(
					$sections,
					static fn (SummarySection $section): bool => $section->isTruncated(),
				),
				'emptySections' => self::codesOf(
					$sections,
					static fn (SummarySection $section): bool => $section->state === SectionState::Empty,
				),
			],
		);
	}

	/**
	 * How many records of every kind the communications section carries - the same count the section
	 * admits being truncated by, told to the journal as it was counted.
	 *
	 * @param array<string, SummarySection> $sections
	 */
	private static function communicationsByKind(array $sections): string
	{
		$data = $sections[DealSectionCode::Communications->value]->data ?? [];

		$counts = [];
		foreach (self::COMMUNICATION_KINDS as $kind)
		{
			$counts[] = $kind . ':' . count($data[$kind] ?? []);
		}

		return implode(',', $counts);
	}

	/**
	 * @param array<string, SummarySection> $sections
	 * @param callable(SummarySection): bool $matches
	 */
	private static function codesOf(array $sections, callable $matches): string
	{
		return implode(',', array_keys(array_filter($sections, $matches)));
	}
}
