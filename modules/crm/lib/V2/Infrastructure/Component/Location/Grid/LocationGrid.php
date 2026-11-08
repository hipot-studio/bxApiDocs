<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Grid;

use Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Column\LocationDataProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Panel\LocationActionPanelProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Row\Action\LocationActionProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Row\LocationRowAssembler;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Panel\Panel;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\UI\PageNavigation;

final class LocationGrid extends Grid
{
	public function __construct(
		Settings $settings,
		private readonly array $columnDescriptions,
		private readonly bool $canEdit = true,
	)
	{
		parent::__construct($settings);
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new LocationDataProvider($this->columnDescriptions),
		);
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new LocationRowAssembler($this->getVisibleColumnsIds()),
			new LocationActionProvider($this->getSettings(), $this->canEdit),
		);
	}

	protected function createPanel(): ?Panel
	{
		return new Panel(
			new LocationActionPanelProvider($this->getSettings(), $this->canEdit),
		);
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}
}
