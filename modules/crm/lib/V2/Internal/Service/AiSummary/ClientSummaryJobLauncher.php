<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Operation\GenerateClientSummary;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Error;

/**
 * Puts one generation of a client summary into the queue of AI jobs and tells the launch what came
 * of it.
 *
 * Everything the framework of the AI operations answers with is turned into the four outcomes of
 * {@see ClientSummaryJobStatus} here, so the launch itself is left with the one decision that is its
 * own: what the person reads in the chat.
 *
 * Nothing is thrown out of this class. By the time it is called the chat is already open, and a
 * failure of the queue is an answer in that chat and not a refusal of the whole launch ([P3.T5],
 * synchronous branch).
 *
 * @internal
 */
class ClientSummaryJobLauncher
{
	/**
	 * Seconds a competing launch waits for the lock. The one that holds it collects the data and calls
	 * the engine, which takes far longer than that, so waiting out the holder is pointless: what the
	 * short wait is for is the pair of clicks that arrive within a moment of each other.
	 */
	private const LOCK_TIMEOUT = 2;

	private ClientSummaryLock $lock;

	public function __construct(?ClientSummaryLock $lock = null)
	{
		$this->lock = $lock ?? new ClientSummaryLock();
	}

	/**
	 * Tells whether a summary of this entity type is written by an operation of CRM at all.
	 *
	 * The types that reach the chat ({@see ClientSummaryLaunchService::ACTIONS}) and the targets the
	 * operation takes ({@see GenerateClientSummary::SUPPORTED_TARGET_ENTITY_TYPE_IDS}) are two lists
	 * kept in two layers. They hold the same types only for as long as both are kept so, and this is
	 * where the two are made to meet: a type the operation does not take never reaches its queue.
	 */
	public function isSummaryGeneratedFor(int $entityTypeId, int $entityId): bool
	{
		return GenerateClientSummary::isSuitableTarget(new ItemIdentifier($entityTypeId, $entityId));
	}

	/**
	 * Tells whether the summary of this client is already being written for this user.
	 *
	 * Asked before the chat is told that the collecting has started: the stub belongs to the launch that
	 * has nobody ahead of it, and the one that does have is answered with nothing at all - the stub of
	 * the first click is in the same chat already.
	 *
	 * The answer may be out of date by the moment the job is created: between the two lie the collecting
	 * of the data and the call of the engine. A second stub is the whole of the price of that, and the
	 * lock of {@see queue()} narrows even it.
	 *
	 * Whether the launch delivers to a chat is asked of the caller and passed on untouched: a running
	 * job blocks a new launch only while it delivers everything the new one would
	 * ({@see GenerateClientSummary::isJobRunningFor()}), so the question of the stub has to be the very
	 * question the queueing will ask.
	 */
	public function isGenerationRunningFor(
		int $userId,
		int $entityTypeId,
		int $entityId,
		bool $isDeliveredToChat,
	): bool
	{
		return GenerateClientSummary::isJobRunningFor(
			new ItemIdentifier($entityTypeId, $entityId),
			$userId,
			$isDeliveredToChat,
		);
	}

	/**
	 * The chat is given to the job, not looked up by it: the asynchronous finish carries no context of
	 * the launch, so the addressee of the answer travels with the job from here (R-3).
	 *
	 * The whole of the launch is serialized per client and per user, because the operation reads the
	 * jobs that are running long before it writes one of its own: between the two lie the collecting of
	 * the data and the call of the engine, and two clicks arriving together would both pass the reading.
	 * A launch that does not get the lock is one such click, and it is answered the way a duplicate is -
	 * the stub of the first one is on its way to the same chat already.
	 *
	 * The place of the launch travels with the job beside the chat and defaults to the chat, so the
	 * path that was here before this parameter keeps its behaviour whole ([P1.T4]).
	 */
	public function queue(
		int $userId,
		int $entityTypeId,
		int $entityId,
		int $chatId,
		ClientSummaryOrigin $origin = ClientSummaryOrigin::Chat,
	): ClientSummaryJobStatus
	{
		$lockName = self::lockName($userId, $entityTypeId, $entityId);
		$isLockHeld = $this->takeLock($lockName);
		if ($isLockHeld === false)
		{
			return ClientSummaryJobStatus::AlreadyRunning;
		}

		try
		{
			return $this->createJob($userId, $entityTypeId, $entityId, $chatId, $origin);
		}
		finally
		{
			if ($isLockHeld)
			{
				$this->lock->unlock($lockName);
			}
		}
	}

	/**
	 * `null` says the lock itself is unavailable: a launch is never lost to a broken lock, it goes
	 * through unserialized instead, the way it did before the lock was there.
	 */
	private function takeLock(string $name): ?bool
	{
		try
		{
			return $this->lock->lock($name, self::LOCK_TIMEOUT);
		}
		catch (\Throwable $exception)
		{
			AIManager::logger()->error(
				'{date}: {class}: The lock {lockName} of the client summary is unavailable: {message}' . PHP_EOL,
				[
					'class' => self::class,
					'lockName' => $name,
					'message' => $exception->getMessage(),
				],
			);

			return null;
		}
	}

	private static function lockName(int $userId, int $entityTypeId, int $entityId): string
	{
		return sprintf('crm_client_summary_%d_%d_%d', $entityTypeId, $entityId, $userId);
	}

	/**
	 * The work the lock guards: reading the jobs that are already running and writing one of its own.
	 */
	protected function createJob(
		int $userId,
		int $entityTypeId,
		int $entityId,
		int $chatId,
		ClientSummaryOrigin $origin = ClientSummaryOrigin::Chat,
	): ClientSummaryJobStatus
	{
		try
		{
			$result = (new GenerateClientSummary(
				new ItemIdentifier($entityTypeId, $entityId),
				$userId,
				chatId: $chatId,
				origin: $origin,
			))->launch();
		}
		catch (\Throwable $exception)
		{
			AIManager::logger()->error(
				'{date}: {class}: The client summary job for chat {chatId} was not created: {message}' . PHP_EOL,
				[
					'class' => self::class,
					'chatId' => $chatId,
					'message' => $exception->getMessage(),
				],
			);

			return ClientSummaryJobStatus::Failed;
		}

		return $result->isSuccess()
			? ClientSummaryJobStatus::Queued
			: self::readRefusal($result)
		;
	}

	/**
	 * The limit is told apart first: it is the one refusal the person can do something about, and it
	 * never comes together with a duplicate.
	 */
	private static function readRefusal(Result $result): ClientSummaryJobStatus
	{
		$codes = array_map(static fn (Error $error): string => (string)$error->getCode(), $result->getErrors());

		if (in_array(ErrorCode::AI_ENGINE_LIMIT_EXCEEDED, $codes, true))
		{
			return ClientSummaryJobStatus::LimitExceeded;
		}

		if (in_array(ErrorCode::JOB_ALREADY_EXISTS, $codes, true))
		{
			return ClientSummaryJobStatus::AlreadyRunning;
		}

		return ClientSummaryJobStatus::Failed;
	}
}
