<?php

namespace Bitrix\Call\Controller\Filter;

use Bitrix\Call\Idempotence;
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
	private ?string $claimedKey = null;

	public function onBeforeAction(Event $event)
	{
		$arguments = $this->getAction()->getArguments();

		$requestId = null;
		if (
			isset($arguments['callRequest'])
			&& $arguments['callRequest'] instanceof DTO\CallRequest
			&& $arguments['callRequest']->requestId
		)
		{
			$requestId = (string)$arguments['callRequest']->requestId;
		}
		elseif (
			isset($arguments['pushRequest'])
			&& $arguments['pushRequest'] instanceof DTO\CallPushRequest
			&& $arguments['pushRequest']->requestId
		)
		{
			$requestId = (string)$arguments['pushRequest']->requestId;
		}
		elseif (
			isset($arguments['trackFile'])
			&& $arguments['trackFile'] instanceof DTO\TrackFileRequest
			&& $arguments['trackFile']->trackId
		)
		{
			$requestId = 'track:' . $arguments['trackFile']->trackId;
		}

		if ($requestId !== null)
		{
			if (!Idempotence::addKey($requestId))
			{
				$this->addError(new Error("Request is not unique. It has already been processed.", "REQUEST_NOT_UNIQUE"));

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
			$this->claimedKey = $requestId;
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
		// Drop the local handle whether or not we cleared the key — on
		// success the key is intentionally kept for its TTL, and the
		// destructor must not double-clear it.
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
