<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Control\Operation;

use Bitrix\Main\Error;
use Bitrix\Socialnetwork\Control\Command\UpdateCommand;
use Bitrix\Socialnetwork\Control\GroupResult;
use Bitrix\Socialnetwork\Item\Workgroup;
use CSocNetGroup;

class UpdateOperation extends AbstractOperation
{
	protected UpdateCommand $command;
	protected Workgroup $entity;

	public function __construct(UpdateCommand $command)
	{
		$this->command = $command;
	}

	public function run(): GroupResult
	{
		$result = new GroupResult();

		$fields = $this->getFields();
		if ($fields !== [])
		{
			$updateResult = $this->updateGroup($this->command->getId(), $fields, $this->shouldRunSync());

			if (!$updateResult)
			{
				$result->addApplicationError();

				return $result;
			}
		}

		$group = $this->getRegistry()->get($this->command->getId());
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

	protected function shouldRunSync(): bool
	{
		return true;
	}

	protected function updateGroup(int $id, array $fields, bool $sync): bool
	{
		return CSocNetGroup::Update(
			ID: $id,
			arFields: $fields,
			bSync: $sync,
		) !== false;
	}
}
