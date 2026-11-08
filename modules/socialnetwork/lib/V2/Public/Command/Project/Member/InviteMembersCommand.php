<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Member;

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

class InviteMembersCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $projectId,
		#[NotEmpty]
		#[Validatable]
		public readonly ?Dto\Project\MemberCollection $members,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		$result = parent::validate();

		if (!$result->isSuccess() || $this->members === null)
		{
			return $result;
		}

		foreach ($this->members as $member)
		{
			if ($member->type !== MemberEntityType::User)
			{
				$result->addError(new ValidationError('Only users can be invited'));

				break;
			}
		}

		return $result;
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(InviteMembersHandler::class);

		return $handler($this);
	}
}
