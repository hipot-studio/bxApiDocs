<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Entity\User\Absence;
use Bitrix\Intranet\Internal\Integration\IBlock\AbsenceRepository;
use Bitrix\Intranet\UserAbsence;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class AbsenceProvider
{
	private AbsenceRepository $absenceRepository;
	private int $iblockId;

	public function __construct()
	{
		$this->absenceRepository = ServiceLocator::getInstance()->get('intranet.absence.repository');
		$this->iblockId = UserAbsence::getIblockId();
	}

	public function getTypes(): ?EntityCollection
	{
		if ($this->iblockId <= 0)
		{
			return null;
		}

		return $this->absenceRepository->getAvailableTypes($this->iblockId);
	}

	public function getUserAbsences(int $userId, DateTime $dateFrom, DateTime $dateTo): ?EntityCollection
	{
		if ($this->iblockId <= 0)
		{
			return null;
		}

		return $this->absenceRepository->getCollection($userId, $this->iblockId, $dateFrom, $dateTo);
	}

	public function getUserAbsencesByDay(int $userId, ?Date $day = null): ?EntityCollection
	{
		if ($this->iblockId <= 0)
		{
			return null;
		}

		$day ??= new Date();
		$dateStr = $day->format('Y-m-d');

		$dateFrom = new DateTime($dateStr . ' 00:00:00', "Y-m-d H:i:s");
		$dateTo = new DateTime($dateStr . ' 23:59:59', "Y-m-d H:i:s");

		return $this->absenceRepository->getCollection($userId, $this->iblockId, $dateFrom, $dateTo);
	}

	public function addUserAbsence(
		int $userId,
		DateTime $dateFrom,
		DateTime $dateTo,
		string $description,
		string $absenceTypeXmlId = 'VACATION'
	): Result
	{
		$result = new Result();
		if ($this->iblockId <= 0)
		{
			return $result->addError(new Error('Invalid Iblock ID'));
		}

		$absence = new Absence(
			$userId,
			$dateFrom,
			$dateTo,
			$description,
			$absenceTypeXmlId,
			$this->absenceRepository::getTypeCaption($absenceTypeXmlId),
		);
		$result = $this->absenceRepository->set($this->iblockId, $absence);

		UserAbsence::cleanCache();

		return $result;
	}
}
