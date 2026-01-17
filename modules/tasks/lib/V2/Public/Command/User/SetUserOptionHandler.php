<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\User;

use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepositoryInterface;

class SetUserOptionHandler
{
	public function __construct(
		private readonly UserOptionRepositoryInterface $userOptionRepository,
	)
	{

	}
	public function __invoke(SetUserOptionCommand $command): void
	{
		$this->userOptionRepository->add(
			optionDictionary: $command->optionName,
			userId: $command->userId,
			value: $command->optionValue,
		);
	}
}
