<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Moderator;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Public\Dto;

class AddModeratorsCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $projectId,
		#[NotEmpty]
		#[Validatable]
		public readonly ?Dto\Project\MemberCollection $moderatorMembers,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		$result = parent::validate();

		if (!$result->isSuccess() || $this->moderatorMembers === null)
		{
			return $result;
		}

		foreach ($this->moderatorMembers as $moderator)
		{
			if ($moderator->type === MemberEntityType::Department)
			{
				$result->addError(new ValidationError('Departments cannot be assigned as moderators'));

				break;
			}
		}

		return $result;
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(AddModeratorsHandler::class);

		return $handler($this);
	}
}
