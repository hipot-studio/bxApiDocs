<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Owner;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\MemberService;

class SetOwnerHandler
{
	public function __construct(
		private readonly MemberService $memberService,
	)
	{
	}

	public function __invoke(SetOwnerCommand $command): Result
	{
		$this->memberService->setOwner(
			$command->projectId,
			$command->ownerId,
			$command->userId,
		);

		return new Result();
	}
}
