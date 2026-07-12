<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Control\Operation;

use Bitrix\Main\Error;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\Control\GroupResult;
use Bitrix\Socialnetwork\Item\Workgroup;
use CSocNetGroup;

class AddOperation extends AbstractOperation
{
	protected AddCommand $command;
	protected Workgroup $entity;

	public function __construct(AddCommand $command)
	{
		$this->command = $command;
	}

	public function run(): GroupResult
	{
		$result = new GroupResult();

		$fields = $this->getFields();

		$id = $this->createGroup($this->command->getOwnerId(), $fields);

		if ($id === 0)
		{
			$result->addApplicationError();

			return $result;
		}

		$group = $this->getRegistry()->get($id);
		if ($group === null)
		{
			$result->addError(new Error('Group not found'));

			return $result;
		}

		$this->entity = $group;

		$result->setGroup($this->entity);

		return $result;
	}

	protected function getFields(): array
	{
		return $this->getMapper()->toArray($this->command);
	}

	protected function createGroup(int $ownerId, array $fields): int
	{
		return (int)CSocNetGroup::createGroup($ownerId, $fields);
	}
}
