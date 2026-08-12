<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Engine\Action;
use Bitrix\Call\Logger\Logger;

/**
 * Short-circuits {@see \Bitrix\Main\Engine\Controller::getActionResponse()}
 * when the request was marked as an idempotent replay by
 * {@see \Bitrix\Call\Controller\Filter\UniqueRequestFilter}. The action body
 * is skipped and a synthetic success payload is returned, so the caller
 * (typically callcontroller pushing a media-callback to the portal) observes
 * a normal success response instead of a REQUEST_NOT_UNIQUE error and stops
 * the retransmit loop.
 *
 * `parent::getActionResponse()` is invoked for the non-replay path; the
 * trait must be used on a class that extends Bitrix\Main\Engine\Controller.
 *
 * @internal
 */
trait IdempotentReplayableTrait
{
	private bool $idempotentReplay = false;

	public function markIdempotentReplay(): void
	{
		$this->idempotentReplay = true;
	}

	/**
	 * @param Action $action
	 * @return mixed
	 */
	protected function getActionResponse(Action $action)
	{
		if ($this->idempotentReplay)
		{
			Logger::getInstance()->info('IdempotentReplay', [
				'payload' => [
					'controller' => static::class,
					'action' => $action->getName(),
				],
			]);
			return ['result' => true, 'replayed' => true];
		}

		return parent::getActionResponse($action);
	}
}
