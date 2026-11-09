<?php

namespace Bitrix\Sender\Posting;

use Bitrix\Main\DB\Result;
use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sender\Connector;
use Bitrix\Sender\Entity\Segment;
use Bitrix\Sender\GroupConnectorTable;
use Bitrix\Sender\GroupTable;
use Bitrix\Sender\Integration\Crm\Connectors\QueryCount;
use Bitrix\Sender\Integration\Sender\Connectors\Contact;
use Bitrix\Sender\Internals\Model\GroupCounterTable;
use Bitrix\Sender\Internals\Model\GroupStateTable;
use Bitrix\Sender\Internals\Model\GroupThreadTable;
use Bitrix\Sender\Internals\Model\LetterSegmentTable;
use Bitrix\Sender\Internals\Model\LetterTable;
use Bitrix\Sender\Recipient\Type;
use Bitrix\Sender\Runtime\SegmentDataBuilderJob;
use Bitrix\Sender\Service\GroupQueueService;
use Bitrix\Sender\SegmentDataTable;
use Bitrix\Sender\Posting\SegmentThreadStrategy\AbstractThreadStrategy;
use Bitrix\Sender\UI\PageNavigation;
use CModule;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sender\Runtime;
Loc::loadMessages(__FILE__);

class SegmentDataBuilder
{
	/**
	 * @var int
	 */
	private $groupId;

	/**
	 * @var string
	 */
	private $filterId;

	private ?int $groupStateId;

	private $endpoint;

	private $dataFilter = [];
	private const PER_PAGE = 100000;
	private const MINIMAL_PER_PAGE = 500;
	private const SEGMENT_TABLE = 'sender_segment_data';
	private const CONNECTOR_ENTITY = [
		'crm_client' => 'CONTACT',
		'crm_lead' => 'LEAD'
	];

	private const SEGMENT_LOCK_KEY = 'segment_lock_';
	private const SEGMENT_DATA_LOCK_KEY = 'segment_data_lock_';

	/** Guards the forced rebuild: only one force per segment at a time, whatever calls it. */
	public const SEGMENT_ACTUALIZE_KEY = 'segment_actualize_';

	/** The snapshot is being rebuilt: the states are recreated and their agents registered. */
	public const FORCE_STARTED = 'STARTED';

	/** Another force holds the lock of the segment, this one rebuilt nothing. */
	public const FORCE_LOCKED = 'LOCKED';

	/** No connector of the segment materializes a snapshot, so there is nothing to rebuild. */
	public const FORCE_NOTHING_TO_REBUILD = 'NOTHING_TO_REBUILD';

	/** A mailing holds the segment and reads its snapshot: dropping the rows now is not allowed. */
	public const FORCE_HELD_BY_QUEUE = 'HELD_BY_QUEUE';

	public const FILTER_COUNTER_TAG = 'senderGroupFilterCounter';
	private static $isSent = [];

	/**
	 * SegmentDataBuilder constructor.
	 *
	 * @param int $groupId
	 * @param string $filterId
	 * @param array $endpoint
	 */
	public function __construct(
		int $groupId,
		string $filterId,
		array $endpoint = [],
		?int $groupStateId = null
	)
	{
		$this->groupId = $groupId;
		$this->filterId = $filterId;
		$this->endpoint = $endpoint;
		$this->groupStateId = $groupStateId;
	}

	private static function checkBlockers()
	{
		$query = "
SELECT b.ID, b.GROUP_ID
FROM b_sender_group_state b
INNER JOIN (
    SELECT GROUP_ID, FILTER_ID
    FROM b_sender_group_state
    GROUP BY GROUP_ID, FILTER_ID
    HAVING COUNT(*) > 1
) d ON b.GROUP_ID = d.GROUP_ID AND b.FILTER_ID = d.FILTER_ID;
";

		$dbResult = \Bitrix\Main\Application::getConnection()->query($query);
		$groups = [];
		while ($row = $dbResult->fetch()) {
			$groupId = $row['GROUP_ID'];
			if (in_array($groupId, $groups))
			{
				continue;
			}
			$id = $row['ID'];
			GroupStateTable::delete($id);
			$groups[] = $groupId;
			Runtime\SegmentDataClearJob::addEventAgent($groupId);
		}
	}

	/**
	 * Read the build state without creating it.
	 *
	 * @return array|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getExistingGroupState(): ?array
	{
		$groupState = GroupStateTable::getList(
			[
				'filter' => $this->groupStateId
					? [
						'=ID' => $this->groupStateId
					]
					: [
						'=FILTER_ID' => $this->filterId,
						'=GROUP_ID'  => $this->groupId,
					]
			]
		)->fetch();

		return $groupState ?: null;
	}

	/**
	 * @return array|bool|false
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getCurrentGroupState()
	{
		$groupState = GroupStateTable::getList(
			[
				'filter' => $this->groupStateId
					? [
						'=ID' => $this->groupStateId
					]
					: [
						'=FILTER_ID' => $this->filterId,
						'=GROUP_ID'  => $this->groupId,
					]
			]
		)->fetch();


		return $groupState ?: $this->createGroupState();
	}
	/**
	 * @return array|bool|false
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getAllStates()
	{
		return GroupStateTable::getList(
			[
				'filter' => [
					'=GROUP_ID'  => $this->groupId,
				]
			]
		)->fetchAll();
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function isBuildingCompleted(): bool
	{
		$groupState = $this->getCurrentGroupState();

		return !$groupState || (int)$groupState['STATE'] === (int)GroupStateTable::STATES['COMPLETED']
			;
	}

	/**
	 * @return ?array
	 * @throws \Exception
	 */
	public function createGroupState(): ?array
	{
		if (!static::checkEndpoint($this->endpoint))
		{
			return null;
		}

		$dataToSet = [
			'FILTER_ID' => $this->filterId,
			'GROUP_ID' => $this->groupId,
			'ENDPOINT' => json_encode($this->endpoint),
			'OFFSET' => 0,
			'STATE' => GroupStateTable::STATES['CREATED'],
			'NEW_CREATED' => true,
		];

		$dataToSet['ID'] = GroupStateTable::add($dataToSet)->getId();

		return $dataToSet;
	}

	/**
	 * @return array
	 * @throws \Exception
	 */
	public function resetGroupState(int $id)
	{
		$dataToSet = [
			'FILTER_ID' => $this->filterId,
			'GROUP_ID' => $this->groupId,
			'ENDPOINT' => json_encode($this->endpoint),
			'OFFSET' => 0,
			'STATE' => GroupStateTable::STATES['CREATED'],
		];

		$dataToSet['ID'] = GroupStateTable::update($id, $dataToSet)->getId();

		return $dataToSet;
	}

	/**
	 * @param int $offset
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function updateGroupStateOffset(int $offset)
	{
		$groupState = $this->getCurrentGroupState();
		if ($groupState)
		{
			GroupStateTable::update(
				$groupState['ID'],
				[
					'FILTER_ID' => $this->filterId,
					'GROUP_ID' => $this->groupId,
					'OFFSET' => $offset,
					'STATE' => GroupStateTable::STATES['IN_PROGRESS'],
				]
			);
		}
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function completeBuilding()
	{
		// The state may have been dropped by a forced rebuild: complete nothing, create nothing.
		$groupState = $this->getExistingGroupState();
		if (!$groupState)
		{
			return;
		}

		GroupStateTable::update(
			$groupState['ID'],
			[
				'STATE' => GroupStateTable::STATES['COMPLETED'],
				'DATE_COMPLETE' => new DateTime(),
			]
		);

		// A busy readiness lock means the answer is unknown, not that the segment is unready,
		// and the counters have nobody else to write them: the build starts by zeroing them and
		// never comes back here. Read the same condition without the lock instead.
		$prepared = self::checkIsSegmentPrepared($this->groupId) ?? self::isSnapshotComplete($this->groupId);

		if ($prepared)
		{
			$this->calculateFilterCounts();
		}
	}

	/**
	 * Readiness of the segment as checkIsSegmentPrepared() decides it, read without its lock:
	 * every state of the segment is completed and every active connector has one.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function isSnapshotComplete(int $groupId): bool
	{
		$states = GroupStateTable::getList([
			'select' => ['FILTER_ID', 'STATE'],
			'filter' => [
				'=GROUP_ID' => $groupId
			],
		])->fetchAll();

		foreach ($states as $state)
		{
			if ((int)$state['STATE'] !== GroupStateTable::STATES['COMPLETED'])
			{
				return false;
			}
		}

		return !self::hasConnectorWithoutState($groupId, $states, null);
	}

	/**
	 * @param int $groupId
	 * @param array[]|null $activeEndpoints Endpoints of the active connectors, keyed by filter
	 *                                      identifier, when the caller has resolved them already.
	 *
	 * @return bool|null Readiness of the segment, or null when the lock of the check is held
	 *                   elsewhere and the readiness has not been decided at all.
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function checkIsSegmentPrepared(int $groupId, ?array $activeEndpoints = null): ?bool
	{
		if (!Locker::lock(self::SEGMENT_LOCK_KEY, $groupId))
		{
			return null;
		}

		$states = GroupStateTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId
			],
		])->fetchAll();

		$currentState = GroupTable::STATUS_READY_TO_USE;
		foreach ($states as $state)
		{
			if ((int)$state['STATE'] !== GroupStateTable::STATES['COMPLETED'])
			{
				if (!SegmentDataBuilderJob::existsInDB($state['ID']))
				{
					SegmentDataBuilderJob::addEventAgent($state['ID']);
				}

				$currentState = GroupTable::STATUS_IN_PROGRESS;
				break;
			}
		}

		if (
			$currentState === GroupTable::STATUS_READY_TO_USE
			&& self::hasConnectorWithoutState($groupId, $states, $activeEndpoints)
		)
		{
			$currentState = GroupTable::STATUS_IN_PROGRESS;
		}

		GroupTable::update($groupId, [
			'fields' => ['STATUS' => $currentState]
		]);

		$prepared = $currentState === GroupTable::STATUS_READY_TO_USE;
		if (CModule::IncludeModule('im') && $prepared)
		{
			$mailings = LetterSegmentTable::getList([
				'select' => [
					'ID' => 'LETTER.ID',
					'USER_ID' => 'LETTER.CREATED_BY',
					'LETTER_STATUS' => 'LETTER.STATUS',
				],
				'filter' => [
					'=SEGMENT_ID' => $groupId,
					'!=LETTER.STATUS' => LetterTable::STATUS_END
				],
			]);
			$group = GroupTable::getById($groupId)->fetchRaw();

			foreach ($mailings as $mailing)
			{
				if (!$mailing['ID'])
				{
					continue;
				}

				if (static::$isSent[$groupId][$mailing['USER_ID']])
				{
					continue;
				}

				// A letter in sending waits for its launch to collect the recipients, not for the segment.
				if ($mailing['LETTER_STATUS'] !== LetterTable::STATUS_SEND)
				{
					LetterTable::update($mailing['ID'], [
						'WAITING_RECIPIENT' => 'N'
					]);
				}

				$notifyTitleCallback = fn (?string $languageId = null) => Loc::getMessage(
					'SENDER_SEGMENT_BUILDER_GROUP_PREPARED_TITLE',
					language: $languageId,
				);

				$notifyMessageCallback = fn (?string $languageId = null) => Loc::getMessage(
					'SENDER_SEGMENT_BUILDER_GROUP_PREPARED',
					[
						"#SEGMENT_ID#" => $groupId,
						"#SEGMENT_NAME#" => htmlspecialcharsbx($group['NAME']),
					],
					$languageId,
				);

				$messageFields = [
					"NOTIFY_TYPE" => IM_NOTIFY_SYSTEM,
					"NOTIFY_MODULE" => "sender",
					"NOTIFY_EVENT" => "group_prepared",
					"TO_USER_ID" => $mailing['USER_ID'],
					"NOTIFY_TAG" => "SENDER|GROUP_PREPARED|" . $groupId . "|" . $mailing['USER_ID'],
					"NOTIFY_TITLE" => $notifyTitleCallback,
					"NOTIFY_MESSAGE" => $notifyMessageCallback,
				];

				\CIMNotify::Add($messageFields);
				static::$isSent[$groupId][$mailing['USER_ID']] = $mailing['USER_ID'];
			}
		}

		Locker::unlock(self::SEGMENT_LOCK_KEY, $groupId);

		return $prepared;
	}

	/**
	 * True when a connector that materializes a snapshot has no build state at all:
	 * such a segment is not prepared, its snapshot is empty.
	 *
	 * @param array[] $states States of the segment.
	 * @param array[]|null $activeEndpoints Endpoints of the active connectors, keyed by filter
	 *                                      identifier; resolved here when the caller has none.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function hasConnectorWithoutState(int $groupId, array $states, ?array $activeEndpoints): bool
	{
		$knownFilterIds = array_flip(array_column($states, 'FILTER_ID'));
		$activeFilterIds = $activeEndpoints === null
			? SegmentSnapshotState::getActiveFilterIds($groupId)
			: array_keys($activeEndpoints);

		foreach ($activeFilterIds as $filterId)
		{
			if (!isset($knownFilterIds[$filterId]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function haltBuilding()
	{
		$groupState = $this->getCurrentGroupState();
		if ($groupState)
		{
			GroupStateTable::update(
				$groupState['ID'],
				[
					'STATE' => GroupStateTable::STATES['HALTED'],
				]
			);
		}
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function clearBuilding(int $groupStateId)
	{
		if ($groupStateId)
		{
			GroupStateTable::delete($groupStateId);
		}

		SegmentDataTable::deleteList([
			'=GROUP_ID' => $this->groupId,
			'=FILTER_ID' => $this->filterId,
		]);
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function clearGroupBuilding(int $groupId)
	{
		if ($groupId)
		{
			$filter = [
				'=GROUP_ID' => $groupId,
			];

			GroupStateTable::deleteList($filter);
			Runtime\SegmentDataClearJob::addEventAgent($groupId);
		}
	}

	/**
	 * @param Result $data
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function addToDB(?Result $data)
	{
		if ($data)
		{
			$rows = [];
			$counter = 0;
			while ($row = $data->fetch())
			{
				$rows[] = [
					'GROUP_ID' => $this->groupId,
					'FILTER_ID' => $this->filterId,
					'CRM_ENTITY_ID'  => $row['CRM_ENTITY_ID'],
					'NAME' => $row['NAME'],
					'CRM_ENTITY_TYPE_ID' => $row['CRM_ENTITY_TYPE_ID'],
					'CRM_ENTITY_TYPE' => $row['CRM_ENTITY_TYPE'],
					'CONTACT_ID' => $row['CRM_CONTACT_ID'],
					'COMPANY_ID' => $row['CRM_COMPANY_ID'],
					'EMAIL' => $row['EMAIL'] ?? null,
					'IM' => $row['IM'] ?? null,
					'PHONE' => $row['PHONE'] ?? null,
					'HAS_EMAIL' => $row['EMAIL'] ? 'Y' : 'N',
					'HAS_IMOL' => $row['IM'] ? 'Y' : 'N',
					'HAS_PHONE' => $row['PHONE'] ? 'Y' : 'N',
					'SENDER_TYPE_ID' => $this->detectSenderType($row),
				];
				$counter++;

				if ($counter === self::MINIMAL_PER_PAGE)
				{
					SegmentDataTable::addMulti($rows, true);
					$rows = [];
					$counter = 0;
				}
			}

			if ($rows)
			{
				SegmentDataTable::addMulti($rows, true);
			}
		}
	}

	/**
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function buildData(): bool
	{
		if (!$this->connectorIterable())
		{
			return true;
		}

		// Liveness is decided by the state already read: a second, creating read would let a
		// forced rebuild slip in between and leave two states for the same filter.
		$groupState = $this->getExistingGroupState();

		if (!$groupState || (int)$groupState['STATE'] === GroupStateTable::STATES['COMPLETED'])
		{
			return true;
		}

		// Everything below belongs to this very state: it keys the window lock and the reads of
		// liveness, even when the builder was created by the pair of segment and filter.
		$this->groupStateId = (int)$groupState['ID'];

		$connector = Connector\Manager::getConnector($this->endpoint);
		$connector->setDataTypeId(null);
		$connector->setCheckAccessRights(false);
		$connector->setFieldValues($this->endpoint['FIELDS']);

		$lastId = $connector->getEntityLimitInfo()['lastId'];

		/** @var AbstractThreadStrategy $threadStrategy */
		$threadStrategy = Runtime\Env::getGroupThreadContext();

		$threadStrategy->setGroupStateId($groupState['ID']);

		$threadState = $threadStrategy->checkThreads();
		if ($threadState === AbstractThreadStrategy::THREAD_LOCKED)
		{
			return false;
		}

		// The build is over for another reason than this call: its state is completed or gone,
		// and the registry of threads must not be refilled from here.
		if ($threadState === AbstractThreadStrategy::THREAD_BUILD_COMPLETED)
		{
			return false;
		}

		if ($threadState === AbstractThreadStrategy::THREAD_NEEDED)
		{
			$threadStrategy->fillThreads();
		}

		$threadStrategy->setPerPage(self::PER_PAGE);

		// The cap is checked before the capture: a thread taken here would idle until it expires.
		if ($threadStrategy->isProcessLimited())
		{
			return false;
		}

		if ($threadStrategy->lockThread() === AbstractThreadStrategy::THREAD_UNAVAILABLE)
		{
			return false;
		}

		$offset = $threadStrategy->getOffset();
		$windowLockKey = $this->getWindowLockKey();
		$threadId = (int)$threadStrategy->getThreadId();

		if (!Locker::lock($windowLockKey, $threadId))
		{
			// The thread is already taken: left busy, it would hold the global cap until it expires.
			$threadStrategy->updateStatus(GroupThreadTable::STATUS_NEW);

			return false;
		}

		try
		{
			// While the window was waiting for the lock, a forced rebuild could have dropped the
			// state and the last thread could have completed the build: writing either way is wrong.
			$currentState = $this->getExistingGroupState();
			if (!$currentState || (int)$currentState['STATE'] === GroupStateTable::STATES['COMPLETED'])
			{
				return false;
			}

			// The upper bound is inclusive, so the window starting right on the last identifier
			// still has that one entity to read.
			if ($offset <= $lastId)
			{
				// Both window bounds are inclusive, so the next window starts right after this one.
				$limit = $offset + self::PER_PAGE - 1;

				$this->addToDB(
					$connector->getLimitedData($offset, $limit)
				);

				$threadStrategy->updateStatus(GroupThreadTable::STATUS_NEW);

				return false;
			}
		}
		finally
		{
			Locker::unlock($windowLockKey, $threadId);
		}

		if ($threadStrategy->getThreadId() < $threadStrategy->lastThreadId())
		{
			$threadStrategy->updateStatus(GroupThreadTable::STATUS_DONE);
			return false;
		}

		$threadStrategy->updateStatus(GroupThreadTable::STATUS_NEW);

		if (!$this->getExistingGroupState())
		{
			return false;
		}

		if ($threadStrategy->hasUnprocessedThreads())
		{
			return false;
		}

		// The completion flag goes before the rows of threads disappear: a process that finds the
		// registry empty decides by the state whether the registry has to be refilled.
		$this->completeBuilding();

		return $threadStrategy->finalize();
	}

	/**
	 * Window lock of one thread of one build state: windows of different threads never overlap
	 * by identifier, so only a second run of the same thread has to be kept out. Build states of
	 * one segment number their threads from zero on their own, so a segment in the key would
	 * make the states of two connectors take the tact from each other.
	 *
	 * The helper takes a key and a single number, so the pair is encoded in the key itself:
	 * the build state goes into the key, the thread number stays the number.
	 */
	private function getWindowLockKey(): string
	{
		return self::SEGMENT_DATA_LOCK_KEY . $this->groupStateId;
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function prepareForAgent($rebuild = false)
	{
		if (!$this->connectorIterable())
		{
			return false;
		}

		$groupState = $this->getCurrentGroupState();

		if (isset($groupState['NEW_CREATED'])
			|| $groupState
			&& ($groupState['ENDPOINT'] !== json_encode($this->endpoint) || $rebuild))
		{
			SegmentDataBuilderJob::removeAgentFromDB($groupState['ID']);
			$this->clearBuilding($groupState['ID']);
			GroupCounterTable::deleteByGroupId($this->groupId);

			$groupState = $this->getCurrentGroupState();

			if ($groupState)
			{
				GroupTable::update($this->groupId, [
					'fields' => ['STATUS' => GroupTable::STATUS_IN_PROGRESS]
				]);

				SegmentDataBuilderJob::addEventAgent($groupState['ID']);

				return true;
			}
		}

		return false;
	}

	/**
	 * @return \Bitrix\Main\ORM\Query\Query
	 * @throws \Exception
	 */
	public function getQuery(): \Bitrix\Main\ORM\Query\Query
	{

		$query = SegmentDataTable::query();
		$query->setFilter(
			[
				'=GROUP_ID' => $this->groupId,
				'=FILTER_ID' => $this->filterId,
			]
		);

		$query->registerRuntimeField(new Entity\ExpressionField('CRM_COMPANY_ID' , '%s', ['COMPANY_ID']));
		$query->registerRuntimeField(new Entity\ExpressionField('CRM_CONTACT_ID' , '%s', ['CONTACT_ID']));

		$query->setSelect(
			[
				'CRM_ENTITY_ID',
				'NAME',
				'CRM_ENTITY_TYPE_ID',
				'CRM_ENTITY_TYPE',
				'CRM_CONTACT_ID',
				'CRM_COMPANY_ID',
			]
		);

		return $query;
	}

	private function prepareEntityTypeFilter($type)
	{
		switch ($type) {
			case Type::EMAIL:
				return ['=HAS_EMAIL' => 'Y'];
			case Type::IM:
				return ['=HAS_IMOL' => 'Y'];
			case Type::PHONE:
				return ['=HAS_PHONE' => 'Y'];
			case Type::CRM_COMPANY_ID:
				return  ['!=COMPANY_ID' => null];
			case Type::CRM_CONTACT_ID:
				return ['!=CONTACT_ID' => null];
			case Type::CRM_DEAL_PRODUCT_CONTACT_ID:
			case Type::CRM_ORDER_PRODUCT_CONTACT_ID:
			case Type::CRM_DEAL_PRODUCT_COMPANY_ID:
			case Type::CRM_ORDER_PRODUCT_COMPANY_ID:
			default:
				return null;
		}
	}

	public function setDataFilter(array $filter = []): SegmentDataBuilder
	{
		$this->dataFilter = [];

		$whiteList = [
			'EMAIL' => '=EMAIL',
			'PHONE' => '=PHONE',
			'IM' => '=IM',
			'NAME' => 'NAME',
			'SENDER_RECIPIENT_TYPE_ID' => "",
		];

		foreach ($filter as $key => $filterValue)
		{
			if (!isset($whiteList[$key]) || $filterValue == "undefined")
			{
				continue;
			}

			if ($key === 'SENDER_RECIPIENT_TYPE_ID')
			{
				$type =  $this->prepareEntityTypeFilter($filterValue);
				if ($type)
				{
					$this->dataFilter[] =  $this->prepareEntityTypeFilter($filterValue);
				}

				continue;
			}

			$this->dataFilter[$whiteList[$key]] = $filterValue;
		}

		return $this;
	}
	/**
	 * @return Result
	 * @throws \Exception
	 */
	public function getData(PageNavigation $nav = null, bool $useFilterId = true): Result
	{
		$params = [
			'select' => [
				'*',
				'CRM_COMPANY_ID' => 'COMPANY_ID',
				'CRM_CONTACT_ID' => 'CONTACT_ID',
			],
			'filter' => $this->prepareFilter($useFilterId),
		];

		if ($nav)
		{
			$params['limit'] = $nav->getLimit();
			$params['offset'] = $nav->getOffset();
		}

		return SegmentDataTable::getList($params);
	}

	private function prepareFilter(bool $useFilterId = true)
	{
		$filter = [];
		$filter['=GROUP_ID'] = $this->groupId;

		if ($useFilterId)
		{
			$filter['=FILTER_ID'] = $this->filterId;
		}

		if ($this->dataFilter)
		{
			$filter = array_merge($this->dataFilter, $filter);
		}

		return $filter;
	}

	/**
	 * @return Connector\Result
	 * @throws \Exception
	 */
	public function getPreparedData(): Connector\Result
	{
		$connector = Connector\Manager::getConnector($this->endpoint);

		$personalizeList = array();
		$personalizeListTmp = $connector->getPersonalizeList();
		foreach($personalizeListTmp as $tag)
		{
			if(!empty($tag['ITEMS']))
			{
				foreach ($tag['ITEMS'] as $item)
				{
					$personalizeList[$item['CODE']] = $item['CODE'];
				}
				continue;
			}
			if(strlen($tag['CODE']) > 0)
			{
				$personalizeList[] = $tag['CODE'];
			}
		}

		$result = new Connector\Result($this->getData());
		$result->setFilterFields($personalizeList);
		$result->setDataTypeId($connector->getDataTypeId());

		return $result;
	}

	/**
	 * @return int
	 * @throws \Exception
	 */
	public function getDataCount(bool $useFilterId = true): int
	{
		return SegmentDataTable::getCount($this->prepareFilter($useFilterId));
	}

	private function connectorIterable()
	{
		$connector = Connector\Manager::getConnector($this->endpoint);

		return $connector instanceof Connector\IncrementallyConnector;
	}

	/**
	 * @param $groupStateId
	 *
	 * @return string
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function run($groupStateId)
	{
		$groupState = GroupStateTable::getById($groupStateId)->fetch();

		if (!$groupState['FILTER_ID'])
		{
			GroupStateTable::update(
				$groupStateId,
				[
					'STATE' => GroupStateTable::STATES['COMPLETED'],
				]
			);

			if ($groupState['GROUP_ID'])
			{
				self::checkIsSegmentPrepared($groupState['GROUP_ID']);
			}
			
			return '';
		}
		$segmentBuilder = new SegmentDataBuilder(
			(int)$groupState['GROUP_ID'],
			$groupState['FILTER_ID'],
			json_decode($groupState['ENDPOINT'], true),
			$groupState['ID']
		);

		if (!$segmentBuilder->buildData())
		{
			return SegmentDataBuilderJob::getAgentName($groupStateId);
		}

		return '';
	}

	/**
	 * @return Connector\DataCounter
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function calculateCurrentFilterCount()
	{
		$groupState = $this->getCurrentGroupState();

		if (!$groupState)
		{
			return new Connector\DataCounter([]);
		}

		$connector = Connector\Manager::getConnector(
			json_decode($groupState['ENDPOINT'], true)
		);

		if (!$connector)
		{
			$this->clearBuilding($groupState['ID']);
			return new Connector\DataCounter([]);
		}

		$counter = new Connector\DataCounter(QueryCount::getPreparedCount(
			$this->getQuery(),
			self::SEGMENT_TABLE,
			self::CONNECTOR_ENTITY[$connector->getCode()]
		));

		Segment::updateAddressCounters($this->groupId, [$counter]);
		$this->sendFilterCounter($this->filterId, $counter, $groupState);

		return $counter;
	}

	/**
	 * Publish the counter of one filter to the segment form.
	 *
	 * @param array|null $groupState Build state of the filter.
	 */
	private function sendFilterCounter(string $filterId, Connector\DataCounter $counter, ?array $groupState): void
	{
		if (!$groupState || !CModule::IncludeModule('pull'))
		{
			return;
		}

		\CPullWatch::AddToStack(
			self::FILTER_COUNTER_TAG,
			[
				'module_id' => 'sender',
				'command' => 'updateFilterCounter',
				'params' => [
					'groupId' => $this->groupId,
					'filterId' => $filterId,
					'count' => $counter->getArray(),
					'state' => $groupState['STATE'],
					'completed' => (int)$groupState['STATE'] === GroupStateTable::STATES['COMPLETED']
				],
			]
		);
	}

	/**
	 * Calculate all current counters
	 * @return Connector\DataCounter[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function calculateFilterCounts(): array
	{
		$connectors = GroupConnectorTable::getList(
			[
				'filter' => ['=GROUP_ID' => $this->groupId]
			])->fetchAll();

		$statesByFilterId = array_column($this->getAllStates(), null, 'FILTER_ID');
		$ownFilterId = $this->filterId;
		$counters = [];

		try
		{
			foreach ($connectors as $dbConnector)
			{
				$endpoint = $dbConnector['ENDPOINT'];
				$connector = Connector\Manager::getConnector(
					$endpoint
				);
				if (!$connector)
				{
					continue;
				}

				$filterId = SegmentSnapshotState::resolveFilterId($dbConnector, $connector);
				$this->filterId = $filterId ?? $ownFilterId;
				if ($connector instanceof Contact)
				{
					$connector->setCheckAccessRights(false);
					$connector->setFieldValues($endpoint['FIELDS']);
				}
				$entity = self::CONNECTOR_ENTITY[$connector->getCode()] ?? null;
				$counter = $entity
					? new Connector\DataCounter(QueryCount::getPreparedCount(
						$this->getQuery(),
						self::SEGMENT_TABLE,
						$entity
					))
					: $connector->getDataCounter()
				;

				$counters[] = $counter;

				// A row whose filter identifier does not resolve is counted, but never announced:
				// under the identifier of another connector its number would land in another row
				// of the form and overwrite the right one.
				if ($filterId === null)
				{
					continue;
				}

				$this->sendFilterCounter(
					$filterId,
					$counter,
					$statesByFilterId[$filterId] ?? null
				);
			}
		}
		finally
		{
			$this->filterId = $ownFilterId;
		}

		Segment::updateAddressCounters($this->groupId, $counters);

		return $counters;
	}

	/**
	 * @param array $endpoint
	 *
	 * @return SegmentDataBuilder
	 */
	public function setEndpoint(array $endpoint): SegmentDataBuilder
	{
		$this->endpoint = $endpoint;

		return $this;
	}

	/**
	 * Bring the build states in line with the segment connectors.
	 *
	 * The forced rebuild is guarded by its own lock, so two callers never rebuild the same
	 * segment at once. The one that steps back gets false and rebuilds nothing, and so do the
	 * one that found nothing to rebuild and the one refused because a mailing holds the
	 * segment; forceActualize() tells the three apart.
	 *
	 * @param int $groupId
	 * @param bool $rebuild
	 * @param int|null $ownPostingId Posting of the caller, when the caller is a mailing that has
	 *                               already registered itself in the queue of the segment.
	 *
	 * @return bool true when the forced rebuild has been performed
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function actualize(int $groupId, bool $rebuild = false, ?int $ownPostingId = null): bool
	{
		if ($rebuild)
		{
			return self::forceActualize($groupId, $ownPostingId) === self::FORCE_STARTED;
		}

		$endpoints = SegmentSnapshotState::getActiveEndpoints($groupId);

		self::actualizeStates($groupId, false, $endpoints);
		self::checkIsSegmentPrepared($groupId, $endpoints);

		return false;
	}

	/**
	 * Rebuild the segment snapshot from scratch and tell how the request ended.
	 *
	 * A caller that has to explain the refusal reads the outcome; actualize() keeps the plain
	 * answer to "has the rebuild been performed".
	 *
	 * There is nothing to rebuild when the segment has no connector that materializes a
	 * snapshot: every one of those gets a state of its own here, so the outcome is decided by
	 * the set of them rather than by what the states did.
	 *
	 * @param int $groupId
	 * @param int|null $ownPostingId Posting of the caller, when the caller is a mailing that has
	 *                               already registered itself in the queue of the segment.
	 *
	 * @return string One of the FORCE_* outcomes.
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function forceActualize(int $groupId, ?int $ownPostingId = null): string
	{
		if (!Locker::lock(self::SEGMENT_ACTUALIZE_KEY, $groupId))
		{
			self::checkIsSegmentPrepared($groupId);

			return self::FORCE_LOCKED;
		}

		$endpoints = [];
		$heldByQueue = true;

		try
		{
			$endpoints = SegmentSnapshotState::getActiveEndpoints($groupId);

			// The caller checks the queue before the lock is taken, and a mailing registers
			// itself there before it reads the snapshot. The check is repeated here, as late as
			// it can be made: the resolving of the connectors above still reads only, and
			// everything past this point writes.
			$heldByQueue = (new GroupQueueService())->isGroupHeld($groupId, $ownPostingId);

			if (!$heldByQueue)
			{
				self::actualizeStates($groupId, true, $endpoints);
			}
		}
		finally
		{
			Locker::unlock(self::SEGMENT_ACTUALIZE_KEY, $groupId);
		}

		self::checkIsSegmentPrepared($groupId, $endpoints);

		if ($heldByQueue)
		{
			return self::FORCE_HELD_BY_QUEUE;
		}

		return $endpoints ? self::FORCE_STARTED : self::FORCE_NOTHING_TO_REBUILD;
	}

	/**
	 * @param array[] $endpoints Endpoints of the active connectors, keyed by filter identifier.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function actualizeStates(int $groupId, bool $rebuild, array $endpoints): void
	{
		$states = GroupStateTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId
			],
		])->fetchAll();

		foreach ($endpoints as $filterId => $endpoint)
		{
			$isUsed = false;

			foreach ($states as $state)
			{
				if ($state['FILTER_ID'] === $filterId)
				{
					$isUsed = true;
					break;
				}
			}

			if (!$isUsed)
			{
				$dataBuilder = new SegmentDataBuilder($groupId, $filterId, $endpoint);
				$dataBuilder->prepareForAgent(true);
				$dataBuilder = null;
			}
		}

		foreach ($states as $state)
		{
			$endpoint = json_decode($state['ENDPOINT'], true);
			$dataBuilder = new SegmentDataBuilder($groupId, $state['FILTER_ID'], $endpoint);

			if (!static::checkEndpoint($endpoint))
			{
				$dataBuilder->clearBuilding($state['ID']);
				continue;
			}

			if (!isset($endpoints[$state['FILTER_ID']]))
			{
				$dataBuilder->clearBuilding($state['ID']);
				continue;
			}

			$activeEndpoint = $endpoints[$state['FILTER_ID']];

			// Under a forced rebuild the state below is recreated anyway: rebuilding it here too
			// would drop the snapshot and register the agent twice in one pass.
			if (!$rebuild && SegmentSnapshotState::isEndpointChanged($state['ENDPOINT'], $activeEndpoint))
			{
				$dataBuilder->setEndpoint($activeEndpoint);
				$dataBuilder->prepareForAgent();
			}

			$dataBuilder = null;
		}

		if (!$rebuild)
		{
			return;
		}

		foreach ($states as $state)
		{
			if (!isset($endpoints[$state['FILTER_ID']]))
			{
				continue;
			}

			$dataBuilder = new SegmentDataBuilder($groupId, $state['FILTER_ID'], $endpoints[$state['FILTER_ID']]);
			$dataBuilder->prepareForAgent(true);
			$dataBuilder = null;
		}
	}

	private static function checkEndpoint(?array $endpoint): bool
	{
		return $endpoint && isset($endpoint['FIELDS']) && !empty($endpoint['FIELDS']);
	}

	public static function checkBuild(): void
	{
		$groupStateList = GroupStateTable::getList([
			'select' => [
				'ID',
				'GROUP_ID',
				'FILTER_ID',
				'ENDPOINT',
			],
			'filter' => [
				'!@STATE' => [
						GroupStateTable::STATES['COMPLETED'],
						GroupStateTable::STATES['HALTED'],
					]
			],
		]);

		while ($groupState = $groupStateList->fetch())
		{
			$segmentBuilder = new SegmentDataBuilder(
				(int)$groupState['GROUP_ID'],
				$groupState['FILTER_ID'],
				json_decode($groupState['ENDPOINT'], true),
				(int)$groupState['ID']
			);

			$segmentBuilder->buildData();
			self::checkIsSegmentPrepared((int)$groupState['GROUP_ID']);
		}
	}

	public static function checkNotCompleted(): string
	{
		self::checkBlockers();
		$groupStateList = GroupStateTable::getList([
			'select' => [
				'GROUP_ID',
				'FILTER_ID',
				'ENDPOINT',
			],
			'filter' => [
				'!@STATE' => [
						GroupStateTable::STATES['COMPLETED'],
						GroupStateTable::STATES['HALTED'],
					]
			],
		]);

		while ($groupState = $groupStateList->fetch())
		{
			self::checkIsSegmentPrepared((int)$groupState['GROUP_ID']);
		}

		$groupList = GroupTable::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'=STATUS' => GroupTable::STATUS_IN_PROGRESS
			]
		]);

		while ($group = $groupList->fetch())
		{
			// Not a plain readiness check: a segment whose active connector has no state is stuck
			// in progress, and only actualization creates that state and registers its agent.
			self::actualize((int)$group['ID']);
		}

		return '\\Bitrix\Sender\\Posting\\SegmentDataBuilder::checkNotCompleted();';
	}

	private function detectSenderType(array $row)
	{
		if (isset($row['PROD_CRM_ORDER_ID']) && $row['PROD_CRM_ORDER_ID']
			&& isset($row['CRM_ENTITY_TYPE_ID']) && $row['CRM_ENTITY_TYPE_ID'] == 5)
			return Type::CRM_ORDER_PRODUCT_CONTACT_ID;
		if (isset($row['CRM_ENTITY_TYPE_ID']) && $row['CRM_ENTITY_TYPE_ID'] == 5)
			return Type::CRM_CONTACT_ID;
		if (isset($row['SGT_DEAL_ID']) && isset($row['CRM_ENTITY_TYPE_ID']) && $row['CRM_ENTITY_TYPE_ID'] == 5)
			return Type::CRM_DEAL_PRODUCT_CONTACT_ID;

		if (isset($row['PROD_CRM_ORDER_ID']) && isset($row['CRM_ENTITY_TYPE_ID']) && $row['CRM_ENTITY_TYPE_ID'] == 4)
			return Type::CRM_ORDER_PRODUCT_COMPANY_ID;
		if (isset($row['CRM_ENTITY_TYPE_ID']) && $row['CRM_ENTITY_TYPE_ID'] == 4)
			return Type::CRM_COMPANY_ID;
		if (isset($row['SGT_DEAL_ID']) && isset($row['CRM_ENTITY_TYPE_ID']) && $row['CRM_ENTITY_TYPE_ID'] == 4)
			return Type::CRM_DEAL_PRODUCT_COMPANY_ID;

		if (isset($row['IM']))
			return Type::IM;
		if (isset($row['EMAIL']))
			return Type::EMAIL;
		if (isset($row['PHONE']))
			return Type::PHONE;

		return Type::EMAIL;
	}
}
