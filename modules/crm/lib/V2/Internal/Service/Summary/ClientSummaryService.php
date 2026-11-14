<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Summary\ClientSummary;
use Bitrix\Crm\V2\Internal\Entity\Summary\SectionState;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummaryResult;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Result;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

/**
 * Summary of a client: what the passed user may read about a contact or a company and about the
 * deals of it.
 *
 * The user is always an argument. The service never asks the environment who is calling, because it
 * answers an agent that acts on behalf of someone else.
 *
 * A refusal leaves the {@see Result} with an error of a known code and no data; the aggregates of a
 * history the storage cannot sum up are a refusal too, not an exception let out.
 *
 * @internal
 */
final class ClientSummaryService
{
	public const ERROR_UNSUPPORTED_CLIENT_TYPE = 'SUMMARY_UNSUPPORTED_CLIENT_TYPE';
	public const ERROR_ACCESS_DENIED = 'SUMMARY_ACCESS_DENIED';
	public const ERROR_AGGREGATES_UNAVAILABLE = 'SUMMARY_AGGREGATES_UNAVAILABLE';

	private const LOGGER_ID = 'Integration.AI';

	/**
	 * Deals the history is read with: every deal the aggregates are counted over plus the one probe
	 * record that says the history goes on below them.
	 *
	 * The probe is what the ceiling is told by, and not the count of what came back: a client with
	 * exactly {@see SummaryLimits::AGGREGATE_CAP} deals has its whole history summed up and would
	 * otherwise be told it is incomplete, which is the same lie about the volume of a summary as the
	 * one a silent cut tells, only the other way round.
	 */
	private const PROBED_CAP = SummaryLimits::AGGREGATE_CAP + SummaryLimits::PROBE_RECORD;

	private const CLIENT_TYPE_NAMES = [
		CCrmOwnerType::Contact => 'contact',
		CCrmOwnerType::Company => 'company',
	];

	private readonly DealSelector $dealSelector;
	private readonly ClientProfileReader $profileReader;
	private readonly DealDetailsReader $dealDetailsReader;
	private readonly CommunicationsReader $communicationsReader;
	private readonly LastCallSummaryReader $lastCallSummaryReader;
	private readonly LoggerInterface $logger;

	public function __construct(
		?DealSelector $dealSelector = null,
		?ClientProfileReader $profileReader = null,
		?DealDetailsReader $dealDetailsReader = null,
		?CommunicationsReader $communicationsReader = null,
		?LastCallSummaryReader $lastCallSummaryReader = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->dealSelector = $dealSelector ?? new DealSelector();
		$this->profileReader = $profileReader ?? new ClientProfileReader();
		$this->dealDetailsReader = $dealDetailsReader ?? new DealDetailsReader();
		$this->communicationsReader = $communicationsReader ?? new CommunicationsReader();
		$this->lastCallSummaryReader = $lastCallSummaryReader ?? new LastCallSummaryReader();
		$this->logger = $logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}

	/**
	 * @return Result Success carries `['summary' => SummaryResult]`.
	 */
	public function collect(int $clientTypeId, int $clientId, int $userId): Result
	{
		$result = new Result();
		if (!isset(self::CLIENT_TYPE_NAMES[$clientTypeId]))
		{
			return $result->addError(new Error(
				'Only a contact or a company can be summarized as a client',
				self::ERROR_UNSUPPORTED_CLIENT_TYPE,
			));
		}

		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$permissions->item()->canRead($clientTypeId, $clientId))
		{
			return $result->addError(new Error(
				'The user may not read the client of the summary',
				self::ERROR_ACCESS_DENIED,
			));
		}

		$startedAt = microtime(true);
		$client = new ItemIdentifier($clientTypeId, $clientId);
		$canReadDeals = $permissions->entityType()->canReadItems(CCrmOwnerType::Deal);

		$selection = $canReadDeals ? $this->dealSelector->selectAccessible($client, $userId, self::PROBED_CAP) : [];
		$isSelectionCapped = count($selection) > SummaryLimits::AGGREGATE_CAP;
		$accessibleDeals = array_slice($selection, 0, SummaryLimits::AGGREGATE_CAP);
		$detailIds = self::detailIds($accessibleDeals);
		$detailedDeals = $canReadDeals ? $this->dealSelector->selectDetails($detailIds) : [];
		$profile = $this->profileReader->read($client);

		// the activities of the sources are read once and told to both of those who need them
		$sources = self::communicationSources($client, $detailIds);
		$activities = $this->lastCallSummaryReader->readActivities($sources);
		$lastCall = $this->lastCallSummaryReader->read($activities->items);
		$communications = self::restrictedWhenTheDealsAreOutOfReach(
			$this->communicationsReader->read($sources, $activities, $lastCall),
			$canReadDeals,
		);

		if ($accessibleDeals === [])
		{
			$sections = self::sectionsWithoutDeals($profile, $communications, $canReadDeals);
		}
		else
		{
			try
			{
				$aggregates = new ClientSummary(
					$client,
					CCrmOwnerType::Deal,
					$accessibleDeals,
					$lastCall?->jobResult,
				);
			}
			catch (ArgumentException | InvalidOperationException $exception)
			{
				return $result->addError(new Error(
					$exception->getMessage(),
					self::ERROR_AGGREGATES_UNAVAILABLE,
				));
			}

			$sections = [
				SectionCode::Profile->value => $profile,
				SectionCode::Deals->value => $this->dealDetailsReader->read(
					$detailedDeals,
					count($accessibleDeals),
				),
				SectionCode::Communications->value => $communications,
				SectionCode::Aggregates->value => SummarySection::filled(
					self::aggregatesData($aggregates),
					$isSelectionCapped ? TruncationReason::ItemLimit : null,
				),
			];
		}

		$summary = new SummaryResult(
			self::CLIENT_TYPE_NAMES[$clientTypeId],
			$clientId,
			$sections,
			$isSelectionCapped,
		);

		$this->logCall($summary, $userId, $sections, $startedAt, count($accessibleDeals), count($detailedDeals));

		return $result->setData(['summary' => $summary]);
	}

	/**
	 * Without a single deal to tell about there is nothing to aggregate either, and the aggregates say
	 * the same thing the deals do: no right to read them, or a right and no deals. The communications
	 * are not among them - the client has some of its own whether it ever had a deal.
	 *
	 * @return array<string, SummarySection>
	 */
	private static function sectionsWithoutDeals(
		SummarySection $profile,
		SummarySection $communications,
		bool $canReadDeals,
	): array
	{
		$dealsSection = $canReadDeals ? SummarySection::empty() : SummarySection::restricted();

		return [
			SectionCode::Profile->value => $profile,
			SectionCode::Deals->value => $dealsSection,
			SectionCode::Communications->value => $communications,
			SectionCode::Aggregates->value => $dealsSection,
		];
	}

	/**
	 * The communications of the client itself are covered by the gate of the summary, so the section
	 * admits a missing right only when the client said nothing of its own and the deals that could
	 * have said it are out of reach.
	 */
	private static function restrictedWhenTheDealsAreOutOfReach(
		SummarySection $communications,
		bool $canReadDeals,
	): SummarySection
	{
		if (!$canReadDeals && $communications->state === SectionState::Empty)
		{
			return SummarySection::restricted();
		}

		return $communications;
	}

	/**
	 * The client is a source of communications of its own and comes first among them; the deals are
	 * the ones the summary tells the details of, already filtered by the rights of the user.
	 *
	 * @param int[] $detailIds
	 * @return ItemIdentifier[]
	 */
	private static function communicationSources(ItemIdentifier $client, array $detailIds): array
	{
		$sources = [$client];
		foreach ($detailIds as $dealId)
		{
			$sources[] = new ItemIdentifier(CCrmOwnerType::Deal, $dealId);
		}

		return $sources;
	}

	/**
	 * @param Item[] $accessibleDeals
	 * @return int[]
	 */
	private static function detailIds(array $accessibleDeals): array
	{
		$ids = array_map(static fn (Item $deal): int => $deal->getId(), $accessibleDeals);

		return array_slice($ids, 0, SummaryLimits::DEAL_DETAILS);
	}

	private static function aggregatesData(ClientSummary $aggregates): array
	{
		// the total decides the currency it is counted in, so it is read before the currency itself
		$successfulAmount = $aggregates->getTotalOpportunityOfSuccessfulItems();

		return [
			'dealsCount' => $aggregates->getItemsCount(),
			'successfulDealsCount' => $aggregates->getSuccessfulItemsCount(),
			'lostDealsCount' => $aggregates->getLostItemsCount(),
			'successfulDealsAmount' => $successfulAmount,
			'currency' => $aggregates->getCurrencyIdOfSuccessfulItems(),
			'latestDealId' => $aggregates->getLatestItemIdentifier()?->getEntityId(),
			'latestClosedDealId' => $aggregates->getLatestClosedItemIdentifier()?->getEntityId(),
		];
	}

	/**
	 * @param array<string, SummarySection> $sections
	 */
	private function logCall(
		SummaryResult $summary,
		int $userId,
		array $sections,
		float $startedAt,
		int $dealsAccessible,
		int $dealsEnriched,
	): void
	{
		$this->logger->info(
			'{date}: client summary collected: entityType={entityType}, entityId={entityId}'
			. ', userId={userId}, durationMs={durationMs}, dealsAccessible={dealsAccessible}'
			. ', dealsEnriched={dealsEnriched}, truncatedSections={truncatedSections}'
			. ', emptySections={emptySections}' . PHP_EOL,
			[
				'entityType' => $summary->entityType,
				'entityId' => $summary->entityId,
				'userId' => $userId,
				'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				'dealsAccessible' => $dealsAccessible,
				'dealsEnriched' => $dealsEnriched,
				'truncatedSections' => self::codesOfTruncated($sections),
				'emptySections' => self::codesOfEmpty($sections),
			],
		);
	}

	/**
	 * @param array<string, SummarySection> $sections
	 */
	private static function codesOfTruncated(array $sections): string
	{
		$codes = array_keys(
			array_filter($sections, static fn (SummarySection $section): bool => $section->isTruncated()),
		);

		return implode(',', $codes);
	}

	/**
	 * @param array<string, SummarySection> $sections
	 */
	private static function codesOfEmpty(array $sections): string
	{
		$codes = array_keys(
			array_filter(
				$sections,
				static fn (SummarySection $section): bool => $section->state === SectionState::Empty,
			),
		);

		return implode(',', $codes);
	}
}
