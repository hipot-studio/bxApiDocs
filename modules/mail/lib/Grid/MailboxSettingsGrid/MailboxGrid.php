<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid;

use Bitrix\Mail\Grid\MailboxSettingsGrid\Column\Provider\MailboxDataProvider;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\MailboxRowAssembler;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\LazyLoadTotalCount;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Row\MailboxRows;
use Bitrix\Main\UI\PageNavigation;

final class MailboxGrid extends Grid
{
	use LazyLoadTotalCount;

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new MailboxDataProvider(),
		);
	}

	protected function createRows(): MailboxRows
	{
		$rowAssembler = new MailboxRowAssembler($this->getVisibleColumnsIds(), $this->getSettings());
		$actionsProvider = new Row\Action\MailboxDataProvider($this->getSettings());

		return new MailboxRows($rowAssembler, $actionsProvider);
	}

	protected function createFilter(): ?\Bitrix\Main\Filter\Filter
	{
		$params = [
			'ID' => $this->getId(),
			'WHITE_LIST' => $this->getSettings()->getViewFields(),
		];
		$filterSettings = new \Bitrix\Mail\Grid\MailboxSettingsGrid\Filter\MailboxFilterSettings($params);

		$extraProviders = [];

		return new \Bitrix\Mail\Grid\MailboxSettingsGrid\Filter\MailboxFilter(
			$this->getId(),
			new \Bitrix\Mail\Grid\MailboxSettingsGrid\Filter\Provider\MailboxDataProvider($filterSettings),
			$extraProviders,
			[
				'FILTER_SETTINGS' => $filterSettings,
			],
			[],
		);
	}
}
