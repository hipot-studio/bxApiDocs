<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Dictionary\OfficePresenceErrorCode;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Service\Office\PresenceService;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Public\Command\CheckInDto;

/**
 * The signal of a client standing still: the anchor of a stay and the moment of the device, taken as one
 * more point of the employee and weighed by the presence service the way a stored point is - with the
 * difference that nothing of it is stored. No route, no statistics, no quota and no push follow it.
 *
 * The command stands beside {@see AbstractCommand} and not under it: that base takes the card of an office
 * and asks for the right to edit the directory, while the signal says something about its author alone.
 * The right here is the act itself - the employee is named by the controller, never by the request.
 *
 * The gates are walked in the order of the check-in acceptance, with the kill switch of the signal in
 * front of them all: a client that misbehaves in the field is stopped before it costs the portal anything.
 * A refusal of the storage is journalled and the answer stays empty either way.
 */
class ConfirmPresence
{
	public const ERROR_DISABLED = OfficePresenceErrorCode::DISABLED;
	public const ERROR_INVALID_POINT = OfficePresenceErrorCode::INVALID_POINT;
	public const ERROR_GEO_ENCRYPTION_UNAVAILABLE = OfficePresenceErrorCode::GEO_ENCRYPTION_UNAVAILABLE;

	protected PresenceService $presenceService;

	public function __construct(protected readonly int $userId)
	{
		$this->init();
	}

	protected function init(): void
	{
		$this->presenceService = new PresenceService();
	}

	/**
	 * @param float $latitude latitude of the anchor of the stay
	 * @param float $longitude longitude of the anchor of the stay
	 * @param int $timestamp moment of the signal by the clock of the device, unix seconds
	 * @param int|null $now moment of the call, server time by default; a parameter for the thresholds to be
	 *                      testable and nothing else
	 */
	public function execute(float $latitude, float $longitude, int $timestamp, ?int $now = null): Result
	{
		$result = new Result();

		if (!$this->isSignalEnabled())
		{
			return $result->addError($this->getDisabledError());
		}

		if (!$this->isGeoEncryptionAvailable())
		{
			return $result->addError($this->getGeoEncryptionUnavailableError());
		}

		// A signal with no time of its own is refused rather than dated by the server: the DTO leaves such a
		// time empty, and the service stamps an empty one with the moment of the call - a leave dated by
		// the device would then lose to a signal dated by the server.
		if ($this->userId <= 0 || $timestamp <= 0)
		{
			return $result->addError($this->getInvalidPointError());
		}

		$point = $this->buildPoint($latitude, $longitude, $timestamp);

		try
		{
			$point->validateAdd();
		}
		catch (InvalidDtoException)
		{
			return $result->addError($this->getInvalidPointError());
		}

		$this->applyPresence($point, $now);

		return $result;
	}

	/**
	 * The point in memory: automatic, with no description, so the service measures its distance by the
	 * common rule. Coordinates of zero are not refused here - the service sieves them out itself without
	 * a query, and that rule lives in one place.
	 */
	private function buildPoint(float $latitude, float $longitude, int $timestamp): CheckInDto
	{
		return CheckInDto::createFromArray([
			'userId' => $this->userId,
			'entityType' => CheckInType::AUTOMATIC->value,
			'latitude' => $latitude,
			'longitude' => $longitude,
			'dateCreate' => $timestamp,
		]);
	}

	/**
	 * A refusal of the storage and a failure thrown out of it are both journalled and neither reaches the
	 * client: the answer of the signal carries nothing, and the client has nothing to do about it anyway.
	 */
	private function applyPresence(CheckInDto $point, ?int $now): void
	{
		try
		{
			if (!$this->presenceService->applyPoints($this->userId, [$point], $now))
			{
				$this->logFailure(__FUNCTION__, 'presence of user ' . $this->userId . ' was not stored');
			}
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}
	}

	/**
	 * The seams the two gates of the portal are read through: neither the settings of the portal nor the
	 * crypto of the stand are reachable from a unit test, and a double answers them at these seams.
	 */
	protected function isSignalEnabled(): bool
	{
		return Feature::isOfficePresenceSignalEnabled();
	}

	protected function isGeoEncryptionAvailable(): bool
	{
		return Feature::isGeoEncryptionAvailable();
	}

	protected function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message and never its trace: the
	 * frames of a trace carry their scalar arguments, and the arguments of this path are the coordinates.
	 */
	protected function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}

	private function getDisabledError(): Error
	{
		return new Error('The presence signal is disabled on this portal', self::ERROR_DISABLED);
	}

	private function getGeoEncryptionUnavailableError(): Error
	{
		return new Error(
			'Geo data of a signal cannot be encrypted on this portal',
			self::ERROR_GEO_ENCRYPTION_UNAVAILABLE,
		);
	}

	private function getInvalidPointError(): Error
	{
		return new Error('Invalid point of the presence signal', self::ERROR_INVALID_POINT);
	}
}
