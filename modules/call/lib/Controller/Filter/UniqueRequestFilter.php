<?php

namespace Bitrix\Call\Controller\Filter;

use Bitrix\Call\Idempotence;
use Bitrix\Call\Controller\IdempotentReplayable;
use Bitrix\Call\DTO;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Single point of idempotency for call controller actions: the action's
 * requestId is claimed atomically here via {@see Idempotence::addKey()};
 * action bodies must not call Idempotence themselves. Replaces the legacy
 * isUnique-then-addKey-much-later split that left a tens-of-seconds TOCTOU
 * window during which a concurrent retry with the same requestId could
 * pass the read-side check before the action body got around to claiming.
 *
 * Cleanup contract: a key claimed in {@see self::onBeforeAction()} is
 * released by {@see self::onAfterAction()} when the action returned errors
 * (controller errors / action errors / result.result === false) and by
 * {@see self::__destruct()} when the action threw a Throwable — Controller
 * catches Throwables BEFORE firing triggerOnAfterAction, so onAfterAction
 * does not run on exceptions. On success the key is intentionally kept
 * for its full TTL — that is the duplicate-suppression we want against
 * client retries.
 *
 * @internal
 */
class UniqueRequestFilter extends Base
{
	/**
	 * Short TTL for high-frequency client-initiated actions (answer, decline,
	 * invite, share screen, start record, etc.). Clients generate a fresh
	 * requestId per click.
	 */
	public const TTL_HOT_PATH = 60;

	private ?string $claimedKey = null;
	private int $ttl;

	/**
	 * @param int $ttl Idempotency key TTL in seconds. 0 (default).
	 */
	public function __construct(int $ttl = 0)
	{
		parent::__construct();
		$this->ttl = $ttl;
	}

	public function onBeforeAction(Event $event)
	{
		$requestId = $this->resolveRequestId($this->getAction()->getArguments());

		if ($requestId !== null)
		{
			$claimed = $this->ttl > 0
				? Idempotence::addKey($requestId, $this->ttl)
				: Idempotence::addKey($requestId)
			;
			if (!$claimed)
			{
				// Race-aware: only short-circuit as IdempotentReplay when the
				// original pass has *finished* (state == DONE, set by
				// onAfterAction success branch). If the original is still
				// in-flight — or state is not readable (file-cache fallback) —
				// reject with REQUEST_NOT_UNIQUE so the upstream caller keeps
				// retrying; otherwise we could ack a duplicate while the
				// original then fails and releases the key, losing the
				// callback.
				if (Idempotence::getState($requestId) === Idempotence::STATE_DONE)
				{
					$controller = $this->getAction()->getController();
					if ($controller instanceof IdempotentReplayable)
					{
						$controller->markIdempotentReplay();
						return null;
					}
				}

				$this->addError(new Error("Request is not unique. It has already been processed.", "REQUEST_NOT_UNIQUE"));

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
			$this->claimedKey = $requestId;
		}

		return null;
	}

	/**
	 * Resolves the idempotency key for the current action.
	 *
	 * Track delivery has no caller-supplied requestId and is keyed by its
	 * stable external track id instead.
	 */
	private function resolveRequestId(array $arguments): ?string
	{
		foreach ($arguments as $argument)
		{
			if (
				(
					$argument instanceof DTO\CallRequest
					|| $argument instanceof DTO\CallPushRequest
					|| $argument instanceof DTO\ControllerRequest
					|| $argument instanceof DTO\TrackErrorRequest
					|| $argument instanceof DTO\CloudRecordingRequest
					|| $argument instanceof DTO\CloudRecordingErrorRequest
					|| $argument instanceof DTO\ProcessingStatusRequest
					|| $argument instanceof DTO\CallUserRequest
					|| $argument instanceof DTO\UserRequest
				)
				&& $argument->requestId !== ''
			)
			{
				return (string)$argument->requestId;
			}

			if ($argument instanceof DTO\TrackFileRequest && $argument->trackId)
			{
				return 'track:' . $argument->trackId;
			}
		}

		return null;
	}

	public function onAfterAction(Event $event)
	{
		if ($this->claimedKey === null)
		{
			return;
		}

		if ($this->actionFailed($event))
		{
			Idempotence::clearKey($this->claimedKey);
		}
		else
		{
			// Promote the key from IN_FLIGHT to DONE so concurrent retransmits
			// arriving AFTER this point can be short-circuited as replay.
			// Earlier retransmits saw IN_FLIGHT and were rejected with
			// REQUEST_NOT_UNIQUE — those retries will see DONE on the next
			// attempt and replay cleanly. Use the same TTL as the original
			// claim so DONE inherits the caller's policy (24h default, 60s
			// for hot-path actions).
			$this->ttl > 0
				? Idempotence::markDone($this->claimedKey, $this->ttl)
				: Idempotence::markDone($this->claimedKey)
			;
		}
		// Drop the local handle — on success the key stays for its TTL as
		// DONE; the destructor must not double-clear it.
		$this->claimedKey = null;
	}

	public function __destruct()
	{
		// Safety net for the Throwable path: Controller catches exceptions
		// before triggerOnAfterAction fires, so onAfterAction() above never
		// runs on action errors that bubble as Throwable. Without this the
		// key would block a legitimate retry for its full TTL.
		if ($this->claimedKey !== null)
		{
			Idempotence::clearKey($this->claimedKey);
			$this->claimedKey = null;
		}
	}

	private function actionFailed(Event $event): bool
	{
		$controller = $event->getParameter('controller');
		if ($controller instanceof Errorable && !empty($controller->getErrors()))
		{
			return true;
		}

		$action = $event->getParameter('action');
		if ($action instanceof Errorable && !empty($action->getErrors()))
		{
			return true;
		}

		// Call actions return ['result' => false, 'errorCode' => …, …] on
		// validated failures (missing_chat_id, could_not_lock, call_not_found,
		// user_is_busy, all_users_busy, etc.) — those are NOT successful runs
		// and the key must be released so the client can retry.
		$result = $event->getParameter('result');
		if (is_array($result) && ($result['result'] ?? null) === false)
		{
			return true;
		}

		return false;
	}
}
