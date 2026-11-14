<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\ImOpenLines\Integrations\HumanResources\StructureService;
use Bitrix\ImOpenLines\V2\Department\DepartmentService;

class Department extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Department.list
	 */
	public function listAction(): ?array
	{
		$service = new DepartmentService(StructureService::getInstance());
		$result = $service->getList();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
