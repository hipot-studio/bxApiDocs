<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Socialnetwork\Collab\Analytics\CollabAnalytics;
use Bitrix\Socialnetwork\Collab\Collab;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabAddCommand;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabDeleteCommand;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabUpdateCommand;
use Bitrix\Socialnetwork\Collab\Control\Event\BeforeCollabUpdateEvent;
use Bitrix\Socialnetwork\Collab\Control\Event\CollabAddEvent;
use Bitrix\Socialnetwork\Collab\Control\Event\CollabDeleteEvent;
use Bitrix\Socialnetwork\Collab\Control\Event\CollabUpdateEvent;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddInviteHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddLogEntryHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddMemberHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddMessageHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddModeratorsHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddOptionsHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Add\AddThemeHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Delete\DeleteChatHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Delete\DeleteNoteCollectionHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Delete\DeleteOptionsHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateInviteHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateLogEntryHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateMemberHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateModeratorsHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateNotePermissionsHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateOptionsHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Update\UpdateOwnerHandler;
use Bitrix\Socialnetwork\Collab\Control\Handler\Trait\SplitAccessCodesTrait;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\MessagesAutoDeleteDelay;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Provider\CollabProvider;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Control\AbstractGroupService;
use Bitrix\Socialnetwork\Control\Command\AbstractCommand;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\Control\Command\DeleteCommand;
use Bitrix\Socialnetwork\Control\Command\UpdateCommand;
use Bitrix\Socialnetwork\Control\GroupResult;
use Bitrix\Socialnetwork\Control\Handler\Add\AddFeatureHandler;
use Bitrix\Socialnetwork\Control\Handler\Update\UpdatePermissionsHandler;
use Bitrix\Socialnetwork\Control\Operation\AddOperation;
use Bitrix\Socialnetwork\Control\Operation\UpdateOperation;
use Bitrix\Socialnetwork\Control\Enum\ViewMode;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectAnalytics;
use Bitrix\SocialNetwork\Validation\Validator\NotContainsUrlValidator;

class CollabService extends AbstractGroupService
{
	use SplitAccessCodesTrait;

	protected function getAddHandlers(): array
	{
		return [
			new AddFeatureHandler(),
			new AddThemeHandler(),
			new AddMessageHandler(),
			new AddInviteHandler(),
			new AddMemberHandler(),
			new AddModeratorsHandler(),
			new AddOptionsHandler(),
			new AddLogEntryHandler(),
		];
	}

	protected function getUpdateHandlers(): array
	{
		return [
			new UpdatePermissionsHandler(),
			new UpdateOptionsHandler(),
			new UpdateOwnerHandler(),
			new UpdateInviteHandler(),
			new UpdateMemberHandler(),
			new UpdateModeratorsHandler(),
			new UpdateLogEntryHandler(),
			new UpdateNotePermissionsHandler(),
		];
	}

	protected function getDeleteHandlers(): array
	{
		return [
			new DeleteNoteCollectionHandler(),
			new DeleteOptionsHandler(),
			new DeleteChatHandler(),
		];
	}

	protected function init(): void
	{
		parent::init();
		$this->registry = CollabRegistry::getInstance();
	}

	protected function checkAddCommand(AbstractCommand $command): GroupResult
	{
		$result = new GroupResult();
		if (!$command instanceof CollabAddCommand)
		{
			$result->addError(new Error('Wrong command type'));
		}

		return $result;
	}

	protected function checkUpdateCommand(UpdateCommand $command): GroupResult
	{
		$result = new GroupResult();
		if (!$command instanceof CollabUpdateCommand)
		{
			$result->addError(new Error('Wrong command type'));
		}

		return $result;
	}

	protected function validateAddBeforeOperation(AddCommand $command): GroupResult
	{
		if (!$command instanceof CollabAddCommand)
		{
			return new GroupResult();
		}

		return $this->validateStructureNodes(
			attachedAccessCodes: $command->getMembers() ?? [],
			detachedAccessCodes: [],
			initiatorId: $command->getInitiatorId(),
		);
	}

	protected function validateUpdateBeforeOperation(UpdateCommand $command, Workgroup $entityBefore): GroupResult
	{
		if (!$command instanceof CollabUpdateCommand)
		{
			return new GroupResult();
		}

		$result = $this->validateStructureNodes(
			attachedAccessCodes: $command->getAddMembers() ?? [],
			detachedAccessCodes: $command->getDeleteMembers() ?? [],
			initiatorId: $command->getInitiatorId(),
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return (new ExternalMembershipValidator())->validate($command, $entityBefore);
	}

	protected function checkDeleteCommand(DeleteCommand $command): GroupResult
	{
		$result = new GroupResult();
		if (!$command instanceof CollabDeleteCommand)
		{
			$result->addError(new Error('Wrong command type'));
		}

		return $result;
	}

	protected function finalizeAddResult(GroupResult $result): CollabResult
	{
		$this->remapValidationErrors($result);

		return $this->setCollabToResult($result);
	}

	protected function finalizeUpdateResult(GroupResult $result): CollabResult
	{
		$this->remapValidationErrors($result);

		return $this->setCollabToResult($result);
	}

	protected function sendAddEvent(AddCommand $command, Workgroup $entity): void
	{
		if (!$command instanceof CollabAddCommand || !$entity instanceof Collab)
		{
			return;
		}

		ActionMessageBuffer::getInstance()->flush();

		$event = new CollabAddEvent($command, $entity);

		$event->send();

		$this->trackCreateFinish($command, $entity);
	}

	private function trackCreateFinish(CollabAddCommand $command, Collab $entity): void
	{
		$initiatorId = $command->getInitiatorId();

		// Keys/order mirror buildProjectProperties() in the JS wizard (socialnetwork.v2.lib.event-sender).
		// The collab create form (im module) has no member/department picker, does not set project dates and
		// has no main-tool selector, so participants/departments/additional_settings_calendar/main_tool are
		// always at their create-time default here (0/'').
		// access_rights_state is 0 by design: the JS wizard's access_rights_state is a diff of the feature
		// permission matrix vs default, and on the collab create path that matrix always arrives at default
		// (verified live: tasks/blog operations are identical for untouched and customized collabs). The
		// collab form's access toggles land in options (e.g. WHO_CAN_INVITE), which is a different metric.
		// additional_settings_auto_delete is derived from options (the form does send messagesAutoDeleteDelay).
		$properties = [
			'owner_change' => $command->getOwnerId() !== $initiatorId ? 1 : 0,
			'moderators' => count($command->getModeratorMembers() ?? []),
			'participants' => 0,
			'departments' => 0,
			'project_type' => $command->getViewMode() === ViewMode::OPEN ? 'open' : 'closed',
			'main_tool' => '',
			'access_rights_state' => 0,
			'additional_settings_calendar' => 0,
			'additional_settings_auto_delete' => $this->resolveAutoDeleteChanged($command),
		];

		$projectAnalytics = new ProjectAnalytics(new CollabAnalytics());
		$projectAnalytics->trackProjectCreateFinish($entity->getId(), $initiatorId, 'success', $properties);
	}

	private function resolveAutoDeleteChanged(CollabAddCommand $command): int
	{
		$options = $command->getOptions();
		if ($options === null)
		{
			return 0;
		}

		// Mirror scn-button-submit-project.js: normalize to a number (''→0), collab has no non-zero default,
		// so any non-zero delay ('0'→0, '3600'→1) means the user changed it.
		foreach ($options->getValue() as $option)
		{
			if ($option->getName() === MessagesAutoDeleteDelay::DB_NAME)
			{
				return (int)$option->getValue() !== 0 ? 1 : 0;
			}
		}

		return 0;
	}

	protected function sendBeforeUpdateEvent(UpdateCommand $command, Workgroup $entity): void
	{
		if (!$command instanceof CollabUpdateCommand || !$entity instanceof Collab)
		{
			return;
		}

		$event = new BeforeCollabUpdateEvent($command, $entity);

		$event->send();
	}

	protected function sendUpdateEvent(UpdateCommand $command, Workgroup $entityBefore, Workgroup $entityAfter): void
	{
		if (!$command instanceof CollabUpdateCommand || !$entityBefore instanceof Collab || !$entityAfter instanceof Collab)
		{
			return;
		}

		ActionMessageBuffer::getInstance()->flush();

		$event = new CollabUpdateEvent($command, $entityBefore, $entityAfter);

		$event->send();
	}

	protected function sendDeleteEvent(DeleteCommand $command, Workgroup $entityBefore): void
	{
		if (!$command instanceof CollabDeleteCommand || !$entityBefore instanceof Collab)
		{
			return;
		}

		$event = new CollabDeleteEvent($command, $entityBefore);

		$event->send();
	}

	protected function createAddOperation(AddCommand $command): AddOperation
	{
		return new Operation\AddOperation($command);
	}

	protected function createUpdateOperation(UpdateCommand $command): UpdateOperation
	{
		return new Operation\UpdateOperation($command);
	}

	/**
	 * Teams support answers first: on a portal that does not hold them a team code is refused before any node is
	 * resolved. Only what is being attached is checked for visibility and existence - a node gone from the
	 * structure must stay removable, or the project would keep a member no edit can drop.
	 *
	 * @param string[] $attachedAccessCodes
	 * @param string[] $detachedAccessCodes
	 */
	private function validateStructureNodes(
		array $attachedAccessCodes,
		array $detachedAccessCodes,
		int $initiatorId,
	): GroupResult
	{
		[, $attachedNodes] = $this->splitAccessCodes($attachedAccessCodes);
		[, $detachedNodes] = $this->splitAccessCodes($detachedAccessCodes);

		$result = new GroupResult();
		if (empty($attachedNodes) && empty($detachedNodes))
		{
			return $result;
		}

		$structureRelationService = Container::getInstance()->getStructureRelationService();

		$result->merge($structureRelationService->validateTeamsSupported($attachedNodes));
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->merge($structureRelationService->validateNodesVisibility($attachedNodes, $initiatorId));
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->merge($structureRelationService->validateNodesAccess(
			[...$attachedNodes, ...$detachedNodes],
			$initiatorId,
		));
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $result->merge($structureRelationService->validateNodesExist($attachedNodes));
	}

	private function setCollabToResult(GroupResult $result): CollabResult
	{
		$collabResult = (new CollabResult())->merge($result);
		if (!$collabResult->isSuccess())
		{
			return $collabResult;
		}

		$provider = CollabProvider::getInstance();

		$collab = $provider->getCollab((int)$collabResult->getCollab()?->getId());
		if ($collab === null)
		{
			$collabResult->addError(new Error('Collab not found'));

			return $collabResult;
		}

		$collabResult->setGroup($collab);

		return $collabResult;
	}

	private function remapValidationErrors(GroupResult $result): void
	{
		if (!$result->isSuccess())
		{
			foreach ($result->getErrorCollection() as $error)
			{
				if ($error instanceof ValidationError)
				{
					if (
						$error->getCode() === 'name'
						&& $error->getFailedValidator() instanceof NotContainsUrlValidator
					)
					{
						$error->setCode('ERROR_NAME_CONTAINS_URL');
						break;
					}
				}
			}
		}
	}
}
