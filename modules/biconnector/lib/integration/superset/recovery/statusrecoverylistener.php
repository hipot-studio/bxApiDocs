<?php

namespace Bitrix\BIConnector\Integration\Superset\Recovery;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Application;
use Bitrix\Main\Event;

/**
 * Resets the recovery series when the incident is over.
 *
 * The listener never starts a series: attempts are opened by a user visit
 * (SupersetInitializer::initializeOrCheckSupersetStatus), so that a gateway incident does not
 * open a series on every portal at once and flood the proxy with synchronous attempts.
 */
final class StatusRecoveryListener
{
	/**
	 * Statuses that end the incident: either the instance is healthy again or recovery lost its
	 * meaning. Only these clear the series state and allow a new series later.
	 *
	 * LOAD is absent on purpose: it means the instance is not reachable yet (frozen instance or
	 * gateway error), so the running series must survive.
	 */
	private const RESET_STATUSES = [
		SupersetInitializer::SUPERSET_STATUS_READY,
		SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED,
		SupersetInitializer::SUPERSET_STATUS_DELETED,
		SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
		SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE,
		SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED,
	];

	public static function onAfterSupersetStatusChange(Event $event): void
	{
		if (SupersetHostMode::isSelfHosted())
		{
			return;
		}

		$status = (string)$event->getParameter('status');
		if (!in_array($status, self::RESET_STATUSES, true))
		{
			return;
		}

		self::resetRecoveryState();

		if ($status === SupersetInitializer::SUPERSET_STATUS_READY)
		{
			// Deferred: the token push is an HTTP call and this handler runs inside the request
			// that flipped the status (the activation callback among others).
			Application::getInstance()->addBackgroundJob(
				static fn() => SupersetInitializer::syncBiconnectorTokenToProxy(),
			);
		}
	}

	/**
	 * Drops the series state and unschedules the agent. The only way to allow a new series.
	 *
	 * Without a series there is nothing to reset: an option write plus a b_agent delete on every
	 * status change and every license change is a pointless cost. An agent row left without state
	 * is harmless — its first run returns an empty string and the kernel removes the row.
	 */
	public static function resetRecoveryState(): void
	{
		$window = new StatusRecoveryWindow();
		if (!$window->isTracked())
		{
			return;
		}

		$window->clear();
		StatusRecoveryScheduler::cancel();
	}
}
