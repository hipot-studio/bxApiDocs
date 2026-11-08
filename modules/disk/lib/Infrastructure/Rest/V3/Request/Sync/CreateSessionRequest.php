<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * The request of disk.sync.session.create.
 *
 * Both parameters are declared and both are asked for: a parameter of the contour exists by being
 * a property here, and the allowlist of the strict base is built from exactly these declarations,
 * so anything else a client sends is refused rather than ignored.
 *
 * Neither has a default. The depth decides how much of the past the client will be handed, and the
 * polling step is a declaration the portal validates - guessing either on the client's behalf would
 * turn a forgotten parameter into a silently different contract. Both numbers are chosen against
 * what disk.sync.capabilities.get publishes, which is the call a client makes first anyway.
 */
final class CreateSessionRequest extends StrictRequest
{
	/**
	 * How far back the history of files is asked for. The portal may serve less, and says so in
	 * effectiveHistoryDepthDays - hence a depth above what the portal serves is not an error here.
	 */
	public ?int $historyDepthDays = null;

	/**
	 * How often the client intends to come back for changes. Validated against the published bounds
	 * and then only recorded: nothing on the server is timed by it. A value outside the bounds is
	 * refused rather than trimmed - a client that had its step quietly shortened would keep polling
	 * at its own one and find its cursor dropped.
	 */
	public ?int $plannedPollIntervalSeconds = null;

	private const PARAMETER_HISTORY_DEPTH_DAYS = 'historyDepthDays';
	private const PARAMETER_PLANNED_POLL_INTERVAL_SECONDS = 'plannedPollIntervalSeconds';

	/**
	 * @throws RequestValidationException
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		self::assertHistoryDepth($request->historyDepthDays);
		self::assertPlannedPollInterval($request->plannedPollIntervalSeconds, new SyncLimits());

		return $request;
	}

	public function getHistoryDepthDays(): int
	{
		return (int)$this->historyDepthDays;
	}

	public function getPlannedPollIntervalSeconds(): int
	{
		return (int)$this->plannedPollIntervalSeconds;
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function assertHistoryDepth(?int $value): void
	{
		if ($value === null)
		{
			throw self::missing(self::PARAMETER_HISTORY_DEPTH_DAYS);
		}

		if ($value < 1)
		{
			throw self::refusal(
				self::PARAMETER_HISTORY_DEPTH_DAYS,
				'DISK_SYNC_REQUEST_HISTORY_DEPTH_INVALID',
			);
		}
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function assertPlannedPollInterval(?int $value, SyncLimits $limits): void
	{
		if ($value === null)
		{
			throw self::missing(self::PARAMETER_PLANNED_POLL_INTERVAL_SECONDS);
		}

		$min = $limits->getPollIntervalMinSeconds();
		$max = $limits->getPollIntervalMaxSeconds();

		if ($value < $min || $value > $max)
		{
			throw self::refusal(
				self::PARAMETER_PLANNED_POLL_INTERVAL_SECONDS,
				'DISK_SYNC_REQUEST_POLL_INTERVAL_OUT_OF_BOUNDS',
				['#MIN#' => (string)$min, '#MAX#' => (string)$max],
			);
		}
	}

	private static function missing(string $parameter): RequestValidationException
	{
		return self::refusal($parameter, 'DISK_SYNC_REQUEST_PARAMETER_REQUIRED', ['#PARAMETER#' => $parameter]);
	}
}
