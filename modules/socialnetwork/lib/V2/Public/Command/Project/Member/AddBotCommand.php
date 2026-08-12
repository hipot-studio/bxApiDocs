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
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\BotService;
use Bitrix\Socialnetwork\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Socialnetwork\V2\Public\Dto;

class AddBotCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $projectId,
		#[NotEmpty]
		#[Validatable]
		public readonly Dto\Project\MemberCollection $members,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		$result = parent::validate();

		if (!$result->isSuccess())
		{
			return $result;
		}

		$userIdMap = [];
		foreach ($this->members as $member)
		{
			if ($member->type !== MemberEntityType::User)
			{
				return $this->addError($result, 'Cannot add ' . $member->type->value . ' as bot');
			}
			$userIdMap[$member->id] = $member->id;
		}

		if (!$userIdMap)
		{
			$result->addError(new ValidationError('Empty members'));

			return $result;
		}

		$container = Container::getInstance();
		$botAuthId = (string)$container->get(BotService::class)?->getExternalAuthId();

		if (!$botAuthId)
		{
			return $this->addError($result, 'Im module not installed');
		}

		$userRepository = $container->get(UserRepositoryInterface::class);
		$userList = $userRepository->getByIds($userIdMap);

		foreach ($userList as $user)
		{
			if (($user['EXTERNAL_AUTH_ID'] ?? '') !== $botAuthId)
			{
				return $this->addError($result, 'User is not bot');
			}
			unset($userIdMap[$user['ID'] ?? 0]);
		}

		if ($userIdMap)
		{
			return $this->addError($result, 'User is not found');
		}

		return $result;
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(AddBotHandler::class);

		return $handler($this);
	}

	private function addError(ValidationResult $result, string $message): ValidationResult
	{
		$result->addError(new ValidationError($message));

		return $result;
	}
}
