<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Socialnetwork\Collab\Control\Command\ValueObject\CollabOptions;
use Bitrix\Socialnetwork\Collab\Control\Option\Command\SetOptionsCommand;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\WhoCanInviteOption;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertProgress;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectSetOptionsException;

class SetProjectOptions implements HandlerInterface
{
	/**
	 * @throws ProjectSetOptionsException
	 */
	public function __invoke(Workgroup $group, ConvertProgress $progress): void
	{
		$groupId = $group->getId();

		if (!$this->shouldRun($progress))
		{
			return;
		}

		$collabOptions = CollabOptions::create([
			WhoCanInviteOption::NAME => $group->getInitiatePermission(),
		]);

		$optionCommand = (new SetOptionsCommand())
			->setCollabId($groupId)
			->setOptions($collabOptions)
		;

		$service = ServiceLocator::getInstance()->get('socialnetwork.collab.option.service');

		$result = $service->set($optionCommand);

		if (!$result->isSuccess())
		{
			throw new ProjectSetOptionsException(
				implode(', ', $result->getErrorMessages()),
			);
		}

		// To avoid divergence of logic with \Bitrix\Socialnetwork\V2\Internal\EventHandler\HasCollabersHandler
		$this->setHasCollabersOption($groupId);
	}

	protected function shouldRun(ConvertProgress $progress): bool
	{
		return match ($progress->getStatus()) {
			null,
			ConvertStatus::InProgressFromCollab,
			ConvertStatus::StoppedByErrorFromCollab,
			ConvertStatus::CompletedFromCollab => false,
			default => true,
		};
	}

	private function setHasCollabersOption(int $groupId): void
	{
		Container::getInstance()->getHasCollabersService()->updateOption($groupId);
	}
}
