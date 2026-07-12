<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Infrastructure\Controller\Response\AbsenceListResponse;
use Bitrix\Tasks\V2\Public\Command\User\ViewUserAbsenceCommand;
use Bitrix\Tasks\V2\Public\Provider\UserAbsenceProvider;

class Absence extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Absence.get
	 */
	public function getAction(
		UserAbsenceProvider $userAbsenceProvider,
		#[NotEmpty]
		#[ElementsType(typeEnum: Type::Numeric)]
		array $userIds,
	): AbsenceListResponse
	{
		$vacationData = $userAbsenceProvider->get($userIds, $this->userId);

		return new AbsenceListResponse($vacationData);
	}

	/**
	 * @ajaxAction tasks.V2.Absence.view
	 */
	public function viewAction(
		#[PositiveNumber]
		int $userId,
		#[PositiveNumber]
		int $absenceId,
	): ?array
	{
		$result = (new ViewUserAbsenceCommand(
			userId: $userId,
			absenceId: $absenceId,
			currentUserId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
	}
}
