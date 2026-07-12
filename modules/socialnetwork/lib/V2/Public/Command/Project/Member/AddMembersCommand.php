<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Member;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Public\Dto;

class AddMembersCommand extends AbstractCommand
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

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(AddMembersHandler::class);

		return $handler($this);
	}
}
