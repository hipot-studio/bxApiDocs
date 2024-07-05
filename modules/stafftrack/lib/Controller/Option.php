<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Dictionary;
use Bitrix\StaffTrack\Service\OptionService;

class Option extends Controller
{
	private ?int $userId = null;
	private OptionService $service;

	public function init(): void
	{
		parent::init();

		$this->userId = (int)CurrentUser::get()->getId();
		$this->service = OptionService::getInstance();
	}

	public function saveSelectedDepartmentIdAction(int $departmentId): void
	{
		$this->service->save($this->userId, Dictionary\Option::SELECTED_DEPARTMENT_ID, $departmentId);
	}
}