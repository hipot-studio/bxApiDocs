<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;

/**
 * Takes the named people off the staff of an office. The set is named by identifiers of people and not
 * by codes of the entity selector: a client removes out of the staff it has already been shown, and
 * there is no selector anywhere on this path.
 *
 * The call works over a set and has nothing to report back: somebody the office never had brings
 * neither a row nor a refusal with them, so there is no accepted to tell from a rejected here.
 *
 * A removal leaves a person with no office at all rather than sending them back to the one they came
 * from: the storage keeps the office of a person and never the offices they used to be on the staff of.
 */
class RemoveEmployees extends AbstractCommand
{
	public const ERROR_TOO_MANY_USER_IDS = OfficeEmployeeErrorCode::TOO_MANY_USER_IDS;

	/**
	 * The largest set one call may name. The number repeats the ceiling of people in one choice the
	 * module already holds to, and repeats it as a number rather than as a reference: that ceiling is
	 * private to the provider keeping it, and the two bound different things - a set expanded out of
	 * codes there, a set a client names outright here - so they part ways the moment either moves.
	 * The size of one write to the storage is not tied to this either: that size is a setting of the
	 * write, this is a term a client is held to.
	 */
	public const MAX_USER_IDS = 300;

	protected OfficeEmployeeRepository $officeEmployeeRepository;

	protected function init(): void
	{
		parent::init();

		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
	}

	/**
	 * @param int[] $userIds the people to take off the staff of the office
	 */
	public function execute(OfficeDto $officeDto, array $userIds = []): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::UPDATE, $officeDto->id))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validatePrimary();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		// The identifiers are counted as they arrived and the excess refuses the call rather than
		// trimming it: counted once the repetitions among them have collapsed, a set of four hundred
		// would pass a ceiling of three hundred and the client would never learn it named too many.
		if (count($userIds) > self::MAX_USER_IDS)
		{
			return $result->addError($this->getTooManyUserIdsError());
		}

		$userIds = $this->normalizeUserIds($userIds);

		if ($this->officeRepository->getById($officeDto->id) === null)
		{
			return $result->addError($this->getNotFoundError());
		}

		return $this->remove($officeDto->id, $userIds);
	}

	/**
	 * The lock of the office is not taken here, and that is a decision rather than something forgotten:
	 * the neighbouring assignment takes it, so the symmetry is what a reader will look for first. There
	 * is nothing to hold. A removal is one delete by a filter - it reads no state to lean on afterwards,
	 * and a removal of the office running beside it leaves it indifferent, because the rows go either
	 * way. The one thing a lock would add is a queue behind an assignment to the same office, and that
	 * costs more than it is worth.
	 *
	 * @param int[] $userIds
	 */
	private function remove(int $officeId, array $userIds): Result
	{
		$result = new Result();

		$removeResult = $this->officeEmployeeRepository->deleteByOfficeIdAndUserIds($officeId, $userIds);
		if (!$removeResult->isSuccess())
		{
			$this->logFailure(__FUNCTION__, implode('; ', $removeResult->getErrorMessages()));

			return $result->addError($this->getSaveFailedError());
		}

		return $result;
	}

	/**
	 * A set of people carries repetitions and identifiers of nobody just as a set of codes does, and the
	 * storage is handed neither: a repetition costs the filter its length for nothing, and a zero from a
	 * client that counted from the wrong end has no row to match anyway.
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;

			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	/**
	 * A failure of the storage is answered with the code of the staff and not with the one of the card:
	 * nothing of the card was being written, and a client telling one refusal from another by the value
	 * alone would be sent looking at the office instead of at the removal.
	 */
	protected function getSaveFailedError(): Error
	{
		return new Error('Failed to remove the employees of the office', OfficeEmployeeErrorCode::SAVE_FAILED);
	}

	private function getTooManyUserIdsError(): Error
	{
		return new Error('Too many identifiers of employees', self::ERROR_TOO_MANY_USER_IDS);
	}
}
