<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents;

use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\LazyLoadTotalCount;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\UI\PageNavigation;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Column\Provider\AiAgentsDataProvider;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\AiAgentsRowAssembler;


final class AiAgentsGrid extends Grid
{
	use LazyLoadTotalCount;

	private \Bitrix\Main\UI\Filter\Options $filterOptions;

	protected function createColumns(): Columns
	{
		return new Columns(
			new AiAgentsDataProvider($this->getSettings()),
		);
	}

	public function getOrmParams(): array
	{
		$params = parent::getOrmParams();
		$params['select'][] = 'ID';

		$params['group'] = ['ID'];

		return $params;
	}

	protected function createRows(): Rows
	{
		\Bitrix\Main\UI\Extension::load([
			$this->getSettings()->getExtensionLoadName(),
			'ui.common',
			'ui.avatar',
		]);

		$rowAssembler = new AiAgentsRowAssembler($this->getVisibleColumnsIds(), $this->getSettings());
		$actionsProvider = new Row\Action\AiAgentsDataProvider($this->getSettings());

		return new Rows($rowAssembler, $actionsProvider);
	}

	public function setRawRows(iterable $rawValue): void
	{
		parent::setRawRows($rawValue);
	}

	public function hasNextPage(): bool
	{
		$pagination = $this->getPagination();

		if (!$pagination)
		{
			return false;
		}

		$remainingRecords = $pagination->getRecordCount() - $pagination->getOffset();
		$pageSize = $pagination->getPageSize();

		return $remainingRecords > $pageSize;
	}

	protected function getFilterOptions(): \Bitrix\Main\UI\Filter\Options
	{
		if (!empty($this->filterOptions))
		{
			return $this->filterOptions;
		}

		$this->filterOptions = new \Bitrix\Main\UI\Filter\Options($this->getId());

		return $this->filterOptions;
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}

	protected function createPanel(): \Bitrix\Main\Grid\Panel\Panel
	{
		return new \Bitrix\Main\Grid\Panel\Panel(
			new Panel\Action\AiAgentsDataProvider($this->getSettings()),
		);
	}
}
