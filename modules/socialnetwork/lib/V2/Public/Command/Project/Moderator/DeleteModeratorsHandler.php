<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Moderator;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\MemberService;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMemberMapper;

class DeleteModeratorsHandler
{
	public function __construct(
		private readonly ProjectMemberMapper $memberMapper,
		private readonly MemberService $memberService,
	)
	{
	}

	public function __invoke(DeleteModeratorsCommand $command): Result
	{
		$moderators = $this->memberMapper->mapToEntityCollection($command->moderatorMembers) ?? new MemberEntityCollection();

		$this->memberService->deleteModerators(
			$command->projectId,
			$moderators,
			$command->userId,
		);

		return new Result();
	}
}
