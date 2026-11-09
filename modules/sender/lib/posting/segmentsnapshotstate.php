<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Sender\Posting;

use Bitrix\Main\Type\DateTime;
use Bitrix\Sender\Connector;
use Bitrix\Sender\GroupConnectorTable;
use Bitrix\Sender\Internals\Dto\SegmentSnapshotDTO;
use Bitrix\Sender\Internals\Model\GroupStateTable;
use Bitrix\Sender\Internals\Model\GroupThreadTable;
use Bitrix\Sender\Internals\PrettyDate;
use Bitrix\Sender\Service\GroupQueueService;
use Bitrix\Sender\Service\GroupQueueServiceInterface;

/**
 * Point in time view of the segment snapshot: build date, build activity and queue hold.
 *
 * Reads only. Never creates a build state, never changes the segment status.
 * Values are resolved on first use and kept, so a single instance describes one moment;
 * create a new instance after the snapshot has been changed.
 */
class SegmentSnapshotState
{
	/** @var array[]|null */
	private ?array $activeEndpoints = null;

	/** @var array[]|null */
	private ?array $states = null;

	private GroupQueueServiceInterface $groupQueueService;

	public function __construct(
		private readonly int $groupId,
		?GroupQueueServiceInterface $groupQueueService = null
	)
	{
		$this->groupQueueService = $groupQueueService ?? new GroupQueueService();
	}

	/**
	 * Filter identifiers of the segment connectors that materialize a snapshot.
	 *
	 * @return string[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getActiveFilterIds(int $groupId): array
	{
		return array_keys(self::getActiveEndpoints($groupId));
	}

	/**
	 * Endpoints of the segment connectors that materialize a snapshot, keyed by filter identifier.
	 *
	 * @return array[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getActiveEndpoints(int $groupId): array
	{
		if ($groupId <= 0)
		{
			return [];
		}

		$connectors = GroupConnectorTable::getList([
			'select' => ['FILTER_ID', 'ENDPOINT'],
			'filter' => ['=GROUP_ID' => $groupId],
		])->fetchAll();

		$endpoints = [];
		foreach ($connectors as $connector)
		{
			$endpoint = $connector['ENDPOINT'];
			if (!is_array($endpoint) || empty($endpoint['FIELDS']))
			{
				continue;
			}

			$entityConnector = Connector\Manager::getConnector($endpoint);
			$filterId = self::resolveFilterId($connector, $entityConnector);

			if (!$entityConnector instanceof Connector\IncrementallyConnector || !$filterId)
			{
				continue;
			}

			$endpoints[$filterId] = $endpoint;
		}

		return $endpoints;
	}

	/**
	 * Filter identifier of a segment connector row: the form writes it into the column, and a
	 * row saved before the column existed is named by the filter of its own connector.
	 *
	 * @param array $connectorRow Row of GroupConnectorTable.
	 */
	public static function resolveFilterId(array $connectorRow, ?Connector\Base $connector): ?string
	{
		$filterId = $connectorRow['FILTER_ID'] ?? null;
		if (!$filterId && $connector instanceof Connector\BaseFilter)
		{
			$filterId = $connector->getUiFilterId();
		}

		return $filterId ?: null;
	}

	/**
	 * True when the build state was built from a filter description other than the current one.
	 *
	 * The stored value is the JSON the builder wrote when it created the state, the current one
	 * is the endpoint of the connector row. The two are compared as documents: key order carries
	 * no meaning for a filter description, and a value that survives no JSON round trip would
	 * read as a change on every single comparison.
	 *
	 * @param string|null $storedEndpoint Value of GroupStateTable.ENDPOINT.
	 */
	public static function isEndpointChanged(?string $storedEndpoint, array $currentEndpoint): bool
	{
		return self::normalizeEndpoint(json_decode((string)$storedEndpoint, true))
			!== self::normalizeEndpoint(json_decode(json_encode($currentEndpoint), true));
	}

	/**
	 * A snapshot serves a launch only on the day it was completed, as a relative date of a filter
	 * ("today") selects other recipients on the next day, and only when completed after the start
	 * or within the window before it.
	 *
	 * @param DateTime $today Midnight of "today" of the filter, see {@see self::isCompletedOnAnotherDay()}.
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function isObsolete(DateTime $launchStart, int $windowSeconds, DateTime $today): bool
	{
		if (!$this->getFilterIds())
		{
			return false;
		}

		if ($this->hasFilterWithoutState())
		{
			return true;
		}

		if ($this->hasIncompleteState())
		{
			return false;
		}

		if ($this->hasStateWithChangedEndpoint())
		{
			return true;
		}

		$lastBuildDate = $this->getMinCompleteDate();
		if ($lastBuildDate === null)
		{
			return true;
		}

		return self::isOfAnotherDay($lastBuildDate, $today)
			|| $lastBuildDate->getTimestamp() < $launchStart->getTimestamp() - $windowSeconds
		;
	}

	/**
	 * @param DateTime $today Midnight of "today" whose calendar date is the date of the user.
	 */
	public function isCompletedOnAnotherDay(DateTime $today): bool
	{
		$lastBuildDate = $this->getLastBuildDate();

		return $lastBuildDate !== null && self::isOfAnotherDay($lastBuildDate, $today);
	}

	private static function isOfAnotherDay(DateTime $moment, DateTime $today): bool
	{
		return (clone $moment)->toUserTime()->format('Y-m-d') !== $today->format('Y-m-d');
	}

	/**
	 * Date of the last completed build of the whole segment.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getLastBuildDate(): ?DateTime
	{
		if (!$this->getFilterIds() || $this->hasFilterWithoutState() || $this->hasIncompleteState())
		{
			return null;
		}

		return $this->getMinCompleteDate();
	}

	/**
	 * True while the snapshot of any active connector is still being built.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function isBuilding(): bool
	{
		if ($this->hasIncompleteState())
		{
			return true;
		}

		return $this->hasUnfinishedThreads();
	}

	/**
	 * True while the segment is still held by a mailing queue record.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function isHeldByQueue(): bool
	{
		return $this->groupQueueService->isGroupHeld($this->groupId);
	}

	/**
	 * State of this snapshot as the edit form of the segment shows it.
	 *
	 * The block is built here rather than in the DTO: the DTO is private infrastructure of the
	 * module and may not reach up into the domain, while the domain may use it.
	 *
	 * The date is formatted here and nowhere else: the form renders it on the server, and the
	 * script that repaints it after a build asks the server for the very same string.
	 *
	 * @param bool $canEdit Right of the user to change the segment.
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function toDto(bool $canEdit): SegmentSnapshotDTO
	{
		$lastBuildDate = $this->getLastBuildDate();
		$activeFilterIds = array_keys($this->getEndpoints());

		return new SegmentSnapshotDTO(
			lastBuildDate: $lastBuildDate === null ? '' : PrettyDate::formatDateTime($lastBuildDate),
			isBuilding: $this->isBuilding(),
			isInQueue: $this->isHeldByQueue(),
			canRefresh: $canEdit && $activeFilterIds !== [],
			activeFilterIds: $activeFilterIds,
		);
	}

	/**
	 * Endpoints of the active connectors, resolved once per instance.
	 *
	 * Resolving them costs an instance of every connector of the module, so a caller that has
	 * an instance at hand passes the answer on instead of asking for it a second time.
	 *
	 * @return array[]
	 */
	public function getEndpoints(): array
	{
		if ($this->activeEndpoints === null)
		{
			$this->activeEndpoints = self::getActiveEndpoints($this->groupId);
		}

		return $this->activeEndpoints;
	}

	/**
	 * @return string[]
	 */
	private function getFilterIds(): array
	{
		return array_keys($this->getEndpoints());
	}

	/**
	 * Build states of the active connectors only: orphaned states are out of scope.
	 *
	 * @return array[]
	 */
	private function getStates(): array
	{
		if ($this->states !== null)
		{
			return $this->states;
		}

		$filterIds = $this->getFilterIds();
		if (!$filterIds)
		{
			$this->states = [];

			return $this->states;
		}

		$this->states = GroupStateTable::getList([
			'select' => ['ID', 'FILTER_ID', 'STATE', 'DATE_COMPLETE', 'ENDPOINT'],
			'filter' => [
				'=GROUP_ID' => $this->groupId,
				'@FILTER_ID' => $filterIds,
			],
		])->fetchAll();

		return $this->states;
	}

	private function hasFilterWithoutState(): bool
	{
		$knownFilterIds = array_flip(array_column($this->getStates(), 'FILTER_ID'));

		foreach ($this->getFilterIds() as $filterId)
		{
			if (!isset($knownFilterIds[$filterId]))
			{
				return true;
			}
		}

		return false;
	}

	private function hasIncompleteState(): bool
	{
		foreach ($this->getStates() as $state)
		{
			if ((int)$state['STATE'] !== GroupStateTable::STATES['COMPLETED'])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * True when a snapshot of an active connector was built from an older filter description:
	 * the rows are about the wrong audience however young they are.
	 */
	private function hasStateWithChangedEndpoint(): bool
	{
		$endpoints = $this->getEndpoints();

		foreach ($this->getStates() as $state)
		{
			$endpoint = $endpoints[$state['FILTER_ID']] ?? null;

			if ($endpoint !== null && self::isEndpointChanged($state['ENDPOINT'] ?? null, $endpoint))
			{
				return true;
			}
		}

		return false;
	}

	private function hasUnfinishedThreads(): bool
	{
		$stateIds = array_column($this->getStates(), 'ID');
		if (!$stateIds)
		{
			return false;
		}

		// A busy row whose capture has expired runs nothing: its handler is gone, and the build
		// only gets it back by capturing it anew. Counted as unfinished, it would keep answering
		// "the segment is already being built" to the form long after there is nobody to finish.
		// Expiry is bounded as the capture bounds it, which takes a row back at EXPIRE_AT < now.
		return GroupThreadTable::getCount([
			'@GROUP_STATE_ID' => $stateIds,
			[
				'LOGIC' => 'OR',
				['=STATUS' => GroupThreadTable::STATUS_NEW],
				[
					'=STATUS' => GroupThreadTable::STATUS_IN_PROGRESS,
					'>=EXPIRE_AT' => new DateTime(),
				],
			],
		]) > 0;
	}

	/**
	 * Order the keys of a decoded endpoint on every level of it.
	 */
	private static function normalizeEndpoint(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		$normalized = [];
		foreach ($value as $key => $item)
		{
			$normalized[$key] = self::normalizeEndpoint($item);
		}

		ksort($normalized);

		return $normalized;
	}

	/**
	 * Oldest completion date among the states, null when any of them carries no date.
	 */
	private function getMinCompleteDate(): ?DateTime
	{
		$states = $this->getStates();
		if (!$states)
		{
			return null;
		}

		$minDate = null;
		foreach ($states as $state)
		{
			if (!($state['DATE_COMPLETE'] instanceof DateTime))
			{
				return null;
			}

			if ($minDate === null || $state['DATE_COMPLETE']->getTimestamp() < $minDate->getTimestamp())
			{
				$minDate = $state['DATE_COMPLETE'];
			}
		}

		return $minDate;
	}
}
