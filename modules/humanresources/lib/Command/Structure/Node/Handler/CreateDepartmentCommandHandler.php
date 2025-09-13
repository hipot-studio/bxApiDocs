<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Command\Structure\Node\Handler;

use Bitrix\HumanResources\Command\Structure\Node\CreateDepartmentCommand;
use Bitrix\HumanResources\Contract\Service\NodeMemberService;
use Bitrix\HumanResources\Result\Command\Structure\CreateNodeCommandResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\NodeService;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class CreateDepartmentCommandHandler
{
	private NodeService $nodeService;
	private NodeMemberService $nodeMemberService;

	public function __construct()
	{
		$this->nodeService = Container::getNodeService();
		$this->nodeMemberService = Container::getNodeMemberService();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws \Exception
	 */
	public function __invoke(CreateDepartmentCommand $command): CreateNodeCommandResult
	{
		Application::getConnection()->startTransaction();

		try
		{
			$node = $this->nodeService->insertNode($command->node);

			if (!empty($command->userIds))
			{
				$this->nodeMemberService->saveUsersToDepartment($node, $command->userIds);
			}

			Application::getConnection()->commitTransaction();

			return new CreateNodeCommandResult($node);
		}
		catch (\Exception $e)
		{
			Application::getConnection()->rollbackTransaction();

			throw $e;
		}
	}
}