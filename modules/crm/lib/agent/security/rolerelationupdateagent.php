<?php

namespace Bitrix\Crm\Agent\Security;

use Bitrix\Crm\Integration\HumanResources\DepartmentQueries;
use Bitrix\Crm\Integration\HumanResources\HumanResources;
use Bitrix\Crm\Security\Role\Model\RoleRelationTable;
use Bitrix\Crm\Security\Role\Utils\RolePermissionLogContext;
use Bitrix\Crm\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Psr\Log\LoggerInterface;

class RoleRelationUpdateAgent extends \Bitrix\Crm\Agent\AgentBase
{
	public const LIMIT = 500;
	private ?LoggerInterface $logger = null;

	public static function doRun(): bool
	{
		return (new self())->execute();
	}

	public function execute(): bool
	{
		$humanResources = HumanResources::getInstance();
		if (!$humanResources->isUsed())
		{
			$this->setExecutionPeriod(24*60*60);

			return true;
		}

		$this->logger = Container::getInstance()->getLogger('Permissions');
		$items = $this->getItems();

		if (empty($items))
		{
			\Bitrix\Main\Config\Option::delete('crm',['name' => 'canUseHrInPermissions']);

			return false;
		}

		$departmentsQueries = DepartmentQueries::getInstance();

		foreach ($items as $item)
		{
			RolePermissionLogContext::getInstance()->set([
				'agent' => 'RoleRelationAgent',
				'oldRelation' => $item['RELATION'],
				'roleId' => $item['ROLE_ID'],
			]);

			$department = $departmentsQueries->getHrDepartmentByIntranetAccessCode($item['RELATION']);
			if (!$department)
			{
				$this->logger->error('RoleRelationAgent. Department not found for access code: ' . $item['RELATION'] . ' in role #' . $item['ROLE_ID']);
				RoleRelationTable::delete($item['ID']);

				continue;
			}

			$withSubdepartments = str_starts_with($item['RELATION'], 'DR');

			RoleRelationTable::update($item['ID'], [
				'RELATION' => $humanResources->buildAccessCode(
					$withSubdepartments
						? AccessCodeType::HrDepartmentRecursiveType->value
						: AccessCodeType::HrDepartmentType->value,
					$department->id,
				),
			]);
		}


		return true;
	}

	private function getItems(): array
	{
		return RoleRelationTable::query()
			->setSelect(['ID', 'RELATION', 'ROLE_ID'])
			->whereLike('RELATION', 'D%')
			->setLimit(self::LIMIT)
			->fetchAll()
		;
	}
}
