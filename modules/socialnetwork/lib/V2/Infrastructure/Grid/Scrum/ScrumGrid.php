<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum;

use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Panel\Panel;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Scrum\Provider\ScrumFilterDataProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Column\Provider\ScrumDataProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Action\ScrumRowActionsProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler\ScrumRowAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ActionMessage;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Panel\Action\AddToArchiveAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Panel\Action\RemoveFromArchiveAction;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Panel\SharedPanelDataProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\ViewUrlDefaultActionRows;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Public\Command\Project\ArchiveProjectCommand;

class ScrumGrid extends Grid
{
	public function __construct(
		Settings $settings,
		private readonly int $currentUserId = 0,
		private readonly int $contextUserId = 0,
		private readonly string $filterId = '',
	)
	{
		parent::__construct($settings);
	}

	protected function createColumns(): Columns
	{
		return new Columns(new ScrumDataProvider(
			userId: $this->contextUserId,
		));
	}

	protected function createFilter(): ?Filter
	{
		return new Filter(
			$this->getFilterId(),
			new ScrumFilterDataProvider($this->getId(), $this->getFilterId()),
		);
	}

	protected function createRows(): Rows
	{
		return new ViewUrlDefaultActionRows(
			new ScrumRowAssembler($this->getVisibleColumnsIds(), $this->getId(), $this->contextUserId),
			ActionMessage::get(ActionMessage::VIEW),
			new ScrumRowActionsProvider($this->currentUserId),
		);
	}

	protected function createPanel(): ?Panel
	{
		$accessService = Container::getInstance()->getScrumAccessService();

		$archiveHandler = static fn(int $id) => (new ArchiveProjectCommand(projectId: $id, archive: true))->run();
		$unarchiveHandler = static fn(int $id) => (new ArchiveProjectCommand(projectId: $id, archive: false))->run();

		return new Panel(
			new SharedPanelDataProvider(
				new AddToArchiveAction(
					accessService: $accessService,
					archiveHandler: $archiveHandler,
					text: ActionMessage::get(ActionMessage::ADD_TO_ARCHIVE),
					confirmText: ActionMessage::get(ActionMessage::PANEL_CONFIRM),
					gridId: $this->getId(),
				),
				new RemoveFromArchiveAction(
					accessService: $accessService,
					unarchiveHandler: $unarchiveHandler,
					text: ActionMessage::get(ActionMessage::REMOVE_FROM_ARCHIVE),
					confirmText: ActionMessage::get(ActionMessage::PANEL_CONFIRM),
					gridId: $this->getId(),
				),
			),
		);
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}

	private function getFilterId(): string
	{
		return ($this->filterId !== '' ? $this->filterId : $this->getId());
	}
}
