<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Intranet\Public\Dto\User\Absence\ActiveVacationResult;
use Bitrix\Intranet\Public\Provider\User\AbsenceProvider;
use Bitrix\Main\Error;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Pull\Model\PushScheduleVacationClaimTable;

final class VacationClaimService
{
	public const PROTOCOL_LEGACY = 1;
	public const PROTOCOL_CONFIRMATION = 2;
	public const REASON_AUTOMATIC_DISABLED = 'AUTOMATIC_DISABLED';
	public const REASON_AUTOMATIC_UNAVAILABLE = 'AUTOMATIC_UNAVAILABLE';
	public const REASON_NO_ACTIVE_VACATION = 'NO_ACTIVE_VACATION';
	public const REASON_ALREADY_CLAIMED = 'ALREADY_CLAIMED';

	public function __construct(
		private readonly ?PolicyService $policyService = null,
		private readonly ?AbsenceProvider $absenceProvider = null,
	)
	{
	}

	public function claim(int $userId, int $protocolVersion = self::PROTOCOL_LEGACY): Result
	{
		$result = new Result();
		$requiresConfirmation = $protocolVersion >= self::PROTOCOL_CONFIRMATION;
		$policyService = $this->policyService ?? new PolicyService();
		try
		{
			$featureEnabled = $policyService->isFeatureEnabled($userId);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error(
				'Cannot resolve push schedule feature.',
				PolicyService::ERROR_INTERNAL,
			));
		}

		if (!$featureEnabled)
		{
			return $result->addError(new Error(
				'Push schedule feature is disabled.',
				PolicyService::ERROR_FEATURE_DISABLED,
			));
		}

		try
		{
			$policy = $policyService->getStoredPolicy($userId);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot read push schedule policy.', PolicyService::ERROR_INTERNAL));
		}
		if (!$policy['automaticEnabled'])
		{
			return $this->notAllowed($result, self::REASON_AUTOMATIC_DISABLED);
		}

		try
		{
			$automatic = $policyService->getAutomaticAvailability($userId);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot read automatic mode.', PolicyService::ERROR_INTERNAL));
		}

		if (!($automatic['trusted'] ?? false))
		{
			return $result->addError(new Error(
				'Automatic mode integration is unavailable.',
				PolicyService::ERROR_INTEGRATION_UNAVAILABLE,
			));
		}

		if (!($automatic['available'] ?? false))
		{
			return $this->notAllowed($result, self::REASON_AUTOMATIC_UNAVAILABLE);
		}

		if (!Loader::includeModule('intranet'))
		{
			return $result->addError(new Error(
				'Absence integration is unavailable.',
				PolicyService::ERROR_INTEGRATION_UNAVAILABLE,
			));
		}

		try
		{
			$vacationResult = ($this->absenceProvider ?? new AbsenceProvider())->getActiveVacation(
				$userId,
				new DateTime(),
			);
		}
		catch (\Throwable)
		{
			$vacationResult = null;
		}
		if (!$vacationResult instanceof ActiveVacationResult || !$vacationResult->trusted)
		{
			return $result->addError(new Error(
				'Absence integration is unavailable.',
				PolicyService::ERROR_INTEGRATION_UNAVAILABLE,
			));
		}

		$vacation = $vacationResult->vacation;
		if ($vacation === null)
		{
			return $this->notAllowed($result, self::REASON_NO_ACTIVE_VACATION);
		}

		$absenceId = $vacation->absenceId;
		try
		{
			$claimResult = PushScheduleVacationClaimTable::add([
				'USER_ID' => $userId,
				'ABSENCE_ID' => $absenceId,
				'PROTOCOL_VERSION' => $requiresConfirmation
					? self::PROTOCOL_CONFIRMATION
					: self::PROTOCOL_LEGACY
				,
				'CONFIRMED_AT' => $requiresConfirmation ? null : new DateTime(),
			]);
		}
		catch (DuplicateEntryException)
		{
			return $this->resolveExistingClaim(
				$result,
				$userId,
				$absenceId,
				$vacation->dateTo,
				$requiresConfirmation,
			);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot claim vacation popup.', PolicyService::ERROR_INTERNAL));
		}
		if (!$claimResult->isSuccess())
		{
			return $this->resolveExistingClaim(
				$result,
				$userId,
				$absenceId,
				$vacation->dateTo,
				$requiresConfirmation,
			);
		}

		return $this->allowed($result, $absenceId, $vacation->dateTo);
	}

	public function confirm(int $userId, int $absenceId): Result
	{
		$result = new Result();
		$policyService = $this->policyService ?? new PolicyService();
		if (!$policyService->isFeatureEnabled($userId))
		{
			return $result->addError(new Error(
				'Push schedule feature is disabled.',
				PolicyService::ERROR_FEATURE_DISABLED,
			));
		}

		if ($userId <= 0 || $absenceId <= 0)
		{
			return $result->setData(['confirmed' => false]);
		}

		try
		{
			$claim = PushScheduleVacationClaimTable::getByPrimary([
				'USER_ID' => $userId,
				'ABSENCE_ID' => $absenceId,
			])->fetch();
			if (!$claim)
			{
				return $result->setData(['confirmed' => false]);
			}
			if ($claim['CONFIRMED_AT'] === null)
			{
				$updateResult = PushScheduleVacationClaimTable::update(
					['USER_ID' => $userId, 'ABSENCE_ID' => $absenceId],
					['CONFIRMED_AT' => new DateTime()],
				);
				if (!$updateResult->isSuccess())
				{
					return $result->addError(new Error(
						'Cannot confirm vacation popup.',
						PolicyService::ERROR_INTERNAL,
					));
				}
			}
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot confirm vacation popup.', PolicyService::ERROR_INTERNAL));
		}

		return $result->setData(['confirmed' => true]);
	}

	private function resolveExistingClaim(
		Result $result,
		int $userId,
		int $absenceId,
		string $dateTo,
		bool $requiresConfirmation,
	): Result
	{
		try
		{
			$existingClaim = PushScheduleVacationClaimTable::getByPrimary([
				'USER_ID' => $userId,
				'ABSENCE_ID' => $absenceId,
			])->fetch();
		}
		catch (\Throwable)
		{
			$existingClaim = false;
		}
		if (!$existingClaim)
		{
			return $result->addError(new Error('Cannot claim vacation popup.', PolicyService::ERROR_INTERNAL));
		}
		if (
			$existingClaim['CONFIRMED_AT'] !== null
			|| (int)$existingClaim['PROTOCOL_VERSION'] < self::PROTOCOL_CONFIRMATION
		)
		{
			return $this->notAllowed($result, self::REASON_ALREADY_CLAIMED);
		}
		if ($requiresConfirmation)
		{
			return $this->allowed($result, $absenceId, $dateTo);
		}

		try
		{
			$connection = PushScheduleVacationClaimTable::getEntity()->getConnection();
			$confirmedAt = $connection->getSqlHelper()->convertToDbDateTime(new DateTime());
			$connection->queryExecute(
				'UPDATE b_pull_push_schedule_vacation_claim '
				. "SET CONFIRMED_AT = {$confirmedAt} "
				. "WHERE USER_ID = {$userId} AND ABSENCE_ID = {$absenceId} AND CONFIRMED_AT IS NULL",
			);
			if ($connection->getAffectedRowsCount() !== 1)
			{
				return $this->notAllowed($result, self::REASON_ALREADY_CLAIMED);
			}
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot claim vacation popup.', PolicyService::ERROR_INTERNAL));
		}

		return $this->allowed($result, $absenceId, $dateTo);
	}

	private function allowed(Result $result, int $absenceId, string $dateTo): Result
	{
		$result->setData(['claim' => [
			'allowed' => true,
			'reason' => null,
			'absenceId' => $absenceId,
			'dateTo' => $dateTo,
		]]);

		return $result;
	}

	private function notAllowed(Result $result, string $reason): Result
	{
		$result->setData(['claim' => [
			'allowed' => false,
			'reason' => $reason,
			'absenceId' => null,
			'dateTo' => null,
		]]);

		return $result;
	}
}
