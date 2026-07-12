<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Member;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\MemberService;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMemberMapper;

class AddMembersHandler
{
	public function __construct(
		private readonly ProjectMemberMapper $memberMapper,
		private readonly MemberService $memberService,
	)
	{
	}

	public function __invoke(AddMembersCommand $command): Result
	{
		$members = $this->memberMapper->mapToEntityCollection($command->members) ?? new MemberEntityCollection();

		$this->memberService->addMembers(
			$command->projectId,
			$members,
			$command->userId,
		);

		return new Result();
	}
}
