<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionData;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Broker\User;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Communications\CommunicationDirection;
use Bitrix\Crm\V2\Internal\Entity\Communications\CommunicationKind;
use Bitrix\Crm\V2\Internal\Entity\Communications\CommunicationRecord;
use Bitrix\Main\Type\Date;
use CCrmActivity;
use CCrmActivityDirection;

/**
 * The found activities turned into the records of the answer: what kind of conversation it was, who
 * talked, how long and what the speech analysis had to say about it.
 *
 * Everything a record needs is read before the loop and in one read each: the results of the AI jobs
 * through the repository of the queue, the durations through the bridge to the telephony, the
 * interlocutors by one batch parse of the communications, the names of the employees by the broker of
 * the users and the rights of the client cards by one preload of a kind. The loop itself asks the
 * storage for nothing - a history of thirty conversations is answered by a fixed number of queries and
 * not by a number that grows with it.
 *
 * The rights are the part of this that is easy to lose: an administrator is answered by a check that
 * never reaches the storage, so a loop that reads the attributes of a card one by one measures as free
 * and costs a query per interlocutor to everybody else.
 *
 * A summary is told about only when the card it was written of is a card the user may read. The
 * analysis is launched either on the activity alone or on a card the activity belongs to, and in the
 * second case it writes of the correspondence picked for that card - while one activity is bound to
 * several cards at once, and the user reaches the history through the one they are allowed. The check
 * of the rights on the interlocutor does not stand for this one: it closes a name, and the summary, the
 * theme and the intent carry the content itself.
 *
 * The subject of the rights of a client card is the user who called the tool, which is not the current
 * user of the process: the answer is assembled wherever the agent is served from.
 *
 * @internal
 */
class CommunicationRecordAssembler
{
	private readonly JobRepository $jobRepository;
	private readonly User $userBroker;

	public function __construct(?JobRepository $jobRepository = null, ?User $userBroker = null)
	{
		$this->jobRepository = $jobRepository ?? JobRepository::getInstance();
		$this->userBroker = $userBroker ?? Container::getInstance()->getUserBroker();
	}

	/**
	 * @param array<int, array> $activities The conversations of the history, the freshest first: the
	 * order is kept.
	 * @param int $userId Whose rights decide whether the client of a conversation is named.
	 * @return CommunicationRecord[]
	 */
	public function assemble(array $activities, int $userId): array
	{
		$activityIds = self::activityIds($activities);
		if ($activityIds === [])
		{
			return [];
		}

		$this->jobRepository->warmSummarizeCacheForActivities($activityIds);
		$summaryTargets = $this->jobRepository->getSummarizeTargetOwnersByActivity($activityIds);
		$this->warmDurations(self::callIdsOf($activities));
		$communications = $this->readCommunications($activityIds);
		$managers = $this->userBroker->getBunchByIds(self::responsibleIds($activities));
		$this->preloadClientPermissions(
			self::mergedByEntityType(
				self::clientIdsByEntityType($communications),
				self::summaryTargetIdsByEntityType($summaryTargets),
			),
			$userId,
		);

		$records = [];
		foreach ($activities as $activity)
		{
			$records[] = $this->recordOf($activity, $userId, $communications, $managers, $summaryTargets);
		}

		return $records;
	}

	/**
	 * @param array<int, array> $communications
	 * @param array<int, array> $managers
	 * @param array<int, ItemIdentifier|null> $summaryTargets
	 */
	private function recordOf(
		array $activity,
		int $userId,
		array $communications,
		array $managers,
		array $summaryTargets,
	): CommunicationRecord
	{
		$activityId = (int)($activity['ID'] ?? 0);
		$summary = $this->summaryOf($activityId, $userId, $summaryTargets[$activityId] ?? null);
		[$client, $isClientHidden] = $this->clientOf($communications[$activityId] ?? null, $userId);
		$summaryData = self::summaryDataOf($summary);

		return new CommunicationRecord(
			self::kindOf($activity),
			self::dateOf($activity),
			self::directionOf($activity),
			self::managerOf($activity, $managers),
			$client,
			$isClientHidden,
			$this->durationOf($activity),
			self::summaryTextOf($summary),
			self::labelOf($summaryData?->theme),
			self::labelOf($summaryData?->intent),
		);
	}

	/**
	 * The short content of a conversation, or nothing at all when the analysis wrote it of a card the
	 * user may not read.
	 *
	 * A job launched on a card summarizes the correspondence picked for that card, and a conversation
	 * reaches the history through any of the cards it is bound to. Told without the check, the history
	 * of a card the user is allowed would carry the content of the card they are not.
	 *
	 * A job that named no card of its own was launched on the activity itself and is told about: the
	 * activity is in the history because a card the user may read is bound to it.
	 *
	 * The record then looks the way a conversation the analysis never got to looks - empty short
	 * content, no theme and no intent - which the description of the tool calls an ordinary state of a
	 * record and not an error, so nothing in the answer says that a card was hidden.
	 */
	private function summaryOf(int $activityId, int $userId, ?ItemIdentifier $target): ?Result
	{
		if ($target !== null && !$this->canReadClient($userId, $target->getEntityTypeId(), $target->getEntityId()))
		{
			return null;
		}

		return $this->jobRepository->getSummarizeCallTranscriptionResultByActivity($activityId);
	}

	/**
	 * The kind of the conversation, taken from the provider of the activity. A provider outside the
	 * three is never read - the finder asks for no other - and a row that carries one anyway is told
	 * about as a call, which is the kind the history was built around.
	 */
	private static function kindOf(array $activity): CommunicationKind
	{
		return match ((string)($activity['PROVIDER_ID'] ?? ''))
		{
			Email::ACTIVITY_PROVIDER_ID => CommunicationKind::Email,
			OpenLine::ACTIVITY_PROVIDER_ID => CommunicationKind::OpenLine,
			default => CommunicationKind::Call,
		};
	}

	/**
	 * A callback of the telephony keeps the direction it was given: the type of the provider only fills
	 * one that was never set. A conversation with neither is one entered by hand and says so.
	 */
	private static function directionOf(array $activity): CommunicationDirection
	{
		$direction = (int)($activity['DIRECTION'] ?? CCrmActivityDirection::Undefined);

		return match ($direction)
		{
			CCrmActivityDirection::Incoming => CommunicationDirection::Incoming,
			CCrmActivityDirection::Outgoing => CommunicationDirection::Outgoing,
			default => ($activity['PROVIDER_TYPE_ID'] ?? '') === Call::ACTIVITY_PROVIDER_TYPE_CALLBACK
				? CommunicationDirection::Callback
				: CommunicationDirection::Unknown,
		};
	}

	/**
	 * The date of the conversation itself and not of the row of it:
	 * {@see CommunicationActivityFinder::HAPPENED_AT} says why a letter pulled in by the mail sync
	 * carries two different moments and which of them the history was asked about.
	 */
	private static function dateOf(array $activity): string
	{
		$moment = $activity[CommunicationActivityFinder::HAPPENED_AT] ?? null;

		return $moment instanceof Date ? $moment->toString() : (string)$moment;
	}

	/**
	 * @param array|null $communication What the batch parse said about the conversation.
	 * @return array{0: string|null, 1: bool} The name of the interlocutor and whether the card of it is
	 * hidden from the user. A conversation bound to no client at all is not a hidden one.
	 */
	private function clientOf(?array $communication, int $userId): array
	{
		$entityTypeId = (int)($communication['ENTITY_TYPE_ID'] ?? 0);
		$entityId = (int)($communication['ENTITY_ID'] ?? 0);
		if ($entityTypeId <= 0 || $entityId <= 0)
		{
			return [null, false];
		}

		if (!$this->canReadClient($userId, $entityTypeId, $entityId))
		{
			return [null, true];
		}

		return [self::emptyToNull((string)($communication['TITLE'] ?? '')), false];
	}

	/**
	 * Only a call of the telephony has a duration: a letter and a dialogue of an open line last no time
	 * anybody counted, and the statistics are filtered by the origin of the telephony and not by the
	 * origin the activity carries.
	 */
	private function durationOf(array $activity): ?int
	{
		$originId = (string)($activity['ORIGIN_ID'] ?? '');
		if (!VoxImplantManager::isVoxImplantOriginId($originId))
		{
			return null;
		}

		return $this->callDuration(VoxImplantManager::extractCallIdFromOriginId($originId));
	}

	/**
	 * @param array<int, array> $managers
	 */
	private static function managerOf(array $activity, array $managers): ?string
	{
		$responsibleId = (int)($activity['RESPONSIBLE_ID'] ?? 0);

		return self::emptyToNull((string)($managers[$responsibleId]['FORMATTED_NAME'] ?? ''));
	}

	/**
	 * The short content of a conversation, cut to
	 * {@see CommunicationHistoryLimits::SUMMARY_CHARS_PER_ITEM} - which says why the cut is where it is
	 * and why the record does not tell about it.
	 */
	private static function summaryTextOf(?Result $result): ?string
	{
		$payload = $result?->getPayload();
		if (!$payload instanceof SummarizeCallTranscriptionPayload)
		{
			return null;
		}

		return self::emptyToNull(self::firstCharactersOf(
			(string)($payload->summary ?? ''),
			CommunicationHistoryLimits::SUMMARY_CHARS_PER_ITEM,
		));
	}

	/**
	 * The theme of a conversation and the intent of the client, cut to what a label of a record is
	 * allowed. They arrive from the same unbounded column the short content does and travel to the model
	 * in the same answer, so they are bounded the same way and not left to whatever the column holds.
	 */
	private static function labelOf(?string $text): ?string
	{
		return self::emptyToNull(self::firstCharactersOf(
			$text ?? '',
			CommunicationHistoryLimits::LABEL_CHARS_PER_ITEM,
		));
	}

	private static function firstCharactersOf(string $text, int $length): string
	{
		return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length);
	}

	private static function summaryDataOf(?Result $result): ?SummarizeCallTranscriptionData
	{
		$payload = $result?->getPayload();

		return $payload instanceof SummarizeCallTranscriptionPayload ? $payload->data : null;
	}

	/**
	 * @param array<int, array> $activities
	 * @return int[]
	 */
	private static function activityIds(array $activities): array
	{
		return array_values(array_filter(
			array_map(static fn (array $activity): int => (int)($activity['ID'] ?? 0), $activities),
			static fn (int $id): bool => $id > 0,
		));
	}

	/**
	 * Only an origin of the telephony names a call of it, and the statistics are filtered by that name
	 * and not by the origin the activity carries.
	 *
	 * @param array<int, array> $activities
	 * @return string[]
	 */
	private static function callIdsOf(array $activities): array
	{
		$callIds = [];
		foreach ($activities as $activity)
		{
			$originId = (string)($activity['ORIGIN_ID'] ?? '');
			if (VoxImplantManager::isVoxImplantOriginId($originId))
			{
				$callIds[] = VoxImplantManager::extractCallIdFromOriginId($originId);
			}
		}

		return $callIds;
	}

	/**
	 * @param array<int, array> $activities
	 * @return int[]
	 */
	private static function responsibleIds(array $activities): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn (array $activity): int => (int)($activity['RESPONSIBLE_ID'] ?? 0), $activities),
			static fn (int $id): bool => $id > 0,
		)));
	}

	private static function emptyToNull(?string $value): ?string
	{
		return ($value ?? '') === '' ? null : $value;
	}

	/**
	 * @param string[] $callIds
	 */
	protected function warmDurations(array $callIds): void
	{
		VoxImplantManager::warmCallDurations($callIds);
	}

	protected function callDuration(string $callId): ?int
	{
		return VoxImplantManager::getCallDuration($callId);
	}

	/**
	 * {@see CCrmActivity::PrepareCommunicationInfos()} joins the identifiers into the `WHERE` of a raw
	 * query, so the list handed to it has to be integers and nothing else - which is what
	 * {@see self::activityIds()} answers and why the cast lives there and not at the border of the class.
	 *
	 * @param int[] $activityIds
	 * @return array<int, array> The interlocutor of every conversation that has one, keyed by the
	 * activity.
	 */
	protected function readCommunications(array $activityIds): array
	{
		return CCrmActivity::PrepareCommunicationInfos($activityIds);
	}

	/**
	 * The client cards the history names, by kind and without the repeats: an interlocutor of five
	 * conversations is one card and is asked about once.
	 *
	 * @param array<int, array> $communications What the batch parse said about the conversations.
	 * @return array<int, int[]>
	 */
	private static function clientIdsByEntityType(array $communications): array
	{
		$idsByEntityType = [];
		foreach ($communications as $communication)
		{
			$entityTypeId = (int)($communication['ENTITY_TYPE_ID'] ?? 0);
			$entityId = (int)($communication['ENTITY_ID'] ?? 0);
			if ($entityTypeId > 0 && $entityId > 0)
			{
				$idsByEntityType[$entityTypeId][$entityId] = $entityId;
			}
		}

		return array_map('array_values', $idsByEntityType);
	}

	/**
	 * The cards the summaries were written of, by kind and without the repeats: their rights are read in
	 * the same preload the interlocutors are, so a check of them costs the loop no query either.
	 *
	 * @param array<int, ItemIdentifier|null> $summaryTargets
	 * @return array<int, int[]>
	 */
	private static function summaryTargetIdsByEntityType(array $summaryTargets): array
	{
		$idsByEntityType = [];
		foreach ($summaryTargets as $target)
		{
			if ($target !== null)
			{
				$idsByEntityType[$target->getEntityTypeId()][$target->getEntityId()] = $target->getEntityId();
			}
		}

		return array_map('array_values', $idsByEntityType);
	}

	/**
	 * @param array<int, int[]> $left
	 * @param array<int, int[]> $right
	 * @return array<int, int[]>
	 */
	private static function mergedByEntityType(array $left, array $right): array
	{
		foreach ($right as $entityTypeId => $entityIds)
		{
			$left[$entityTypeId] = array_values(array_unique(array_merge($left[$entityTypeId] ?? [], $entityIds)));
		}

		return $left;
	}

	/**
	 * The attributes of the client cards, read by one query of a kind and answered out of the cache of
	 * the security controller by every check of the loop.
	 *
	 * Without it the loop pays a query for every different interlocutor, and the rights of the
	 * communications themselves are no substitute: {@see \CCrmActivity::PrepareCommunicationInfos}
	 * measures the current user of the process, and the history is answered on behalf of whoever called
	 * the tool.
	 *
	 * An administrator is left out of it: their check is answered before the storage is reached, so the
	 * attributes would be read for nobody and the preload would only add queries.
	 *
	 * @param array<int, int[]> $idsByEntityType
	 */
	protected function preloadClientPermissions(array $idsByEntityType, int $userId): void
	{
		if ($idsByEntityType === [])
		{
			return;
		}

		$permissions = Container::getInstance()->getUserPermissions($userId);
		if ($permissions->isAdmin())
		{
			return;
		}

		foreach ($idsByEntityType as $entityTypeId => $entityIds)
		{
			$permissions->item()->preloadPermissionAttributes($entityTypeId, $entityIds);
		}
	}

	protected function canReadClient(int $userId, int $entityTypeId, int $entityId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->item()->canRead($entityTypeId, $entityId);
	}
}
