<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Validation\ProjectValidationGroup;
use Bitrix\Socialnetwork\V2\Public\Dto;

class AddProjectCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly Dto\Project\Project $input,
		#[PositiveNumber]
		public readonly int $userId,
		public readonly bool $isCurrentUserModuleAdmin = false,
		public readonly bool $enforceInitiatorMembership = true,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		return ServiceLocator::getInstance()
			->get('main.validation.service')
			->validate($this, ProjectValidationGroup::Add);
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->getAddProjectHandler();

		return $handler($this);
	}
}
