<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Command\Type;

use Bitrix\Tasks\DI\Container;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\Onboarding\Command\CommandInterface;
use Bitrix\Tasks\Onboarding\Command\Result\CommandResult;
use Bitrix\Tasks\Onboarding\Command\Trait\CommentTrait;
use Bitrix\Tasks\Onboarding\Command\Trait\InvitationTrait;
use Bitrix\Tasks\Onboarding\Comment\ResponsibleInvitationAcceptedComment;
use Bitrix\Tasks\Onboarding\Internal\Type;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotification;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;

class ResponsibleInvitationAcceptedCommand implements CommandInterface
{
	use CommentTrait;
	use InvitationTrait;

	protected int $id;
	protected int $taskId;
	protected int $userId;
	protected Type $type;
	protected string $code;

	protected ?TaskObject $task = null;
	protected ChatNotification $chatNotification;

	public function __construct(int $id, int $taskId, int $userId, Type $type, string $code)
	{
		$this->id = $id;
		$this->taskId = $taskId;
		$this->userId = $userId;
		$this->type = $type;
		$this->code = $code;

		$this->init();
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function __invoke(): CommandResult
	{
		$result = new CommandResult();
		if ($this->task === null)
		{
			return $result;
		}

		if ($this->isInvitedUser($this->task->getResponsibleId()))
		{
			return $result;
		}

		if (FormV2Feature::isOn('', $this->task->getGroupId()))
		{
			$this->postInChat();
		}
		else
		{
			$this->postComment(
				$this->task,
				new ResponsibleInvitationAcceptedComment($this->task)
			);
		}

		return $result;
	}

	protected function init(): void
	{
		$this->task = TaskRegistry::getInstance()->getObject($this->taskId);
		$this->chatNotification = Container::getInstance()->get(ChatNotification::class);
	}

	protected function postInChat(): void
	{
		$taskRepository = Container::getInstance()->get(TaskReadRepositoryInterface::class);
		$task = $taskRepository->getById($this->taskId, new Select(members: true));

		if (!$task || $task->responsible->id === $task->creator->id)
		{
			return;
		}

		$this->chatNotification->notify(
			NotificationType::OnboardingInvitedResponsibleAccept,
			$task,
		);
	}
}
