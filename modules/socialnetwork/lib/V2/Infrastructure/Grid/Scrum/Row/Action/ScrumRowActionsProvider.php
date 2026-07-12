<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Action;

use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ActionMessage;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\ArchiveAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\DeleteAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\EditAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\DeleteIncomingRequestAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\DeleteOutgoingRequestAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\FavoriteAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\JoinAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\LeaveAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\UnarchiveAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\UnfavoriteAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Action\ViewAction;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\GridAccessServiceInterface;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class ScrumRowActionsProvider extends DataProvider
{
	private GridAccessServiceInterface $accessService;

	public function __construct(
		private readonly int $currentUserId = 0,
	)
	{
		parent::__construct();
		$this->accessService = Container::getInstance()->getScrumAccessService();
	}

	public function prepareActions(): array
	{
		return [
			new ViewAction(
				text: ActionMessage::get(ActionMessage::VIEW),
				urlSuffix: 'tasks/?scrum=Y',
			),
			new FavoriteAction(
				text: ActionMessage::get(ActionMessage::ADD_TO_FAVORITES),
				entityType: Type::Scrum->value,
			),
			new UnfavoriteAction(
				text: ActionMessage::get(ActionMessage::REMOVE_FROM_FAVORITES),
				entityType: Type::Scrum->value,
			),
			new EditAction(
				currentUserId: $this->currentUserId,
				accessService: $this->accessService,
				text: ActionMessage::get(ActionMessage::EDIT),
			),
			new JoinAction(
				text: ActionMessage::get(ActionMessage::JOIN),
				entityType: Type::Scrum->value,
			),
			new DeleteOutgoingRequestAction(
				text: ActionMessage::get(ActionMessage::DELETE_OUTGOING_REQUEST),
				entityType: Type::Scrum->value,
			),
			new LeaveAction(
				currentUserId: $this->currentUserId,
				accessService: $this->accessService,
				text: ActionMessage::get(ActionMessage::LEAVE),
			),
			new DeleteIncomingRequestAction(
				text: ActionMessage::get(ActionMessage::DELETE_INCOMING_REQUEST),
				entityType: Type::Scrum->value,
			),
			new ArchiveAction(
				currentUserId: $this->currentUserId,
				accessService: $this->accessService,
				text: ActionMessage::get(ActionMessage::ADD_TO_ARCHIVE),
				entityType: Type::Scrum->value,
			),
			new UnarchiveAction(
				currentUserId: $this->currentUserId,
				accessService: $this->accessService,
				text: ActionMessage::get(ActionMessage::REMOVE_FROM_ARCHIVE),
				entityType: Type::Scrum->value,
			),
			new DeleteAction(
				currentUserId: $this->currentUserId,
				accessService: $this->accessService,
				text: ActionMessage::get(ActionMessage::DELETE),
				entityType: Type::Scrum,
			),
		];
	}
}
