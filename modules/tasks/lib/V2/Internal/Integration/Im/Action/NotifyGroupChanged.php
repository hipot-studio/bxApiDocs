<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\GroupTypes;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\User\Gender;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\FeatureService;
use Bitrix\Tasks\V2\Internal\Util\MBString;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyGroupChanged extends AbstractNotify
{
	private const MESSAGE_CODE_TEMPLATE = 'TASKS_IM_TASK_GROUP_ADDED_OR_CHANGED_%s_%s_MSGVER_1';

	public function __construct(
		private readonly Task $task,
		MessageSenderInterface $sender,
		protected readonly ?User $triggeredBy = null,
		private readonly ChatActionLinkService $chatActionLinkService,
		private readonly FeatureService $featureService,
		private readonly ?Group $newGroup,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		$genderCode = match ($this->triggeredBy?->getGender())
		{
			Gender::Male,
			Gender::Female => $this->triggeredBy?->getGender()->value,
			default => Gender::Male->value,
		};

		$groupTypeCode = match ($this->newGroup?->type)
		{
			GroupTypes::Group->value,
			GroupTypes::Project->value,
			GroupTypes::Collab->value => $this->newGroup?->type,
			default => GroupTypes::Group->value,
		};
		if ($this->featureService->isNewProjectsOn())
		{
			$groupTypeCode = GroupTypes::Project->value;
		}

		$groupTypeCode = mb_strtoupper($groupTypeCode);

		return sprintf(
			static::MESSAGE_CODE_TEMPLATE,
			$groupTypeCode,
			$genderCode,
		);
	}

	public function getMessageData(): array
	{
		$groupLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->getId(),
			action: ChatAction::OpenGroup,
			entityId: (int)$this->newGroup?->id,
		);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#GROUP#' => MBString::ucfirst((string)$this->newGroup?->name),
			'#OPEN_GROUP_URL#' => $groupLink,
		];
	}
}
