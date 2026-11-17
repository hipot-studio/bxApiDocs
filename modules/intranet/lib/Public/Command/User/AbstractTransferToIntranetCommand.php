<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Internal\Integration\Extranet\ExtranetService;
use Bitrix\Intranet\Internal\Integration\Humanresources\DepartmentRepository;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\Service\UserService;
use Bitrix\Main\Result;
use Bitrix\Main\SiteTable;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\NotEmpty;

abstract class AbstractTransferToIntranetCommand extends AbstractCommand
{
	protected array $transferDepartmentIds = [];
	protected string $siteId = '';

	public function __construct(
		#[NotEmpty]
		#[ElementsType(typeEnum: Type::Integer)]
		protected readonly array $departmentIds,
	)
	{
	}

	protected function beforeRun(): ?Result
	{
		$departmentIds = array_values(array_unique($this->departmentIds));
		if (min($departmentIds) <= 0)
		{
			return $this->error('DEPARTMENT_NOT_FOUND');
		}
		try
		{
			$extranetService = new ExtranetService();
			$departments = new DepartmentRepository();
			if (!$extranetService->isAvailable() || !$departments->isAvailable())
			{
				return $this->error('MODULE_UNAVAILABLE');
			}
			if (!$departments->allDepartmentsExist($departmentIds))
			{
				return $this->error('DEPARTMENT_NOT_FOUND');
			}
			$siteId = \CSite::GetDefSite();
			$employeeGroups = array_filter(array_map('intval', (new UserService())->getEmployeeGroupIds((string)$siteId)),
				static fn (int $id): bool => $id > 0 && $id !== 2);
			$extranetGroup = (int)$extranetService->getExtranetUserGroupId();
			if (!$siteId || $siteId === $extranetService->getExtranetSiteId() || !SiteTable::getById($siteId)->fetch()
				|| !$employeeGroups || $extranetGroup <= 0 || $extranetGroup === 2
				|| in_array($extranetGroup, $employeeGroups, true))
			{
				return $this->error('CONFIGURATION_ERROR');
			}
			$groups = [...$employeeGroups, $extranetGroup];
			if (!ServiceContainer::getInstance()->userRepository()->allGroupsExist($groups))
			{
				return $this->error('CONFIGURATION_ERROR');
			}
			$this->transferDepartmentIds = $departmentIds;
			$this->siteId = $siteId;
			return null;
		}
		catch (\Throwable)
		{
			return $this->error('INTERNAL_ERROR');
		}
	}


	private function error(string $code): Result
	{
		return (new Result())->setData(['users' => []])
			->addError(new Error('User transfer cannot be performed', $code));
	}
}
