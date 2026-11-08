<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid;

use Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Column\Provider\CurrencyDataProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Panel\Action\CurrencyDataProvider as CurrencyPanelActionDataProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Action\CurrencyDataProvider as CurrencyRowActionDataProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Row\Assembler\CurrencyRowAssembler;
use Bitrix\Crm\V2\Internal\Repository\Currency\CurrencyRepository;
use Bitrix\Crm\V2\Internal\Service\Currency\Compatibility\LegacyCurrencyMutationAdapter;
use Bitrix\Crm\V2\Internal\Service\Currency\CurrencyMutationService;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Panel\Panel;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\UI\PageNavigation;

class CurrencyGrid extends Grid
{
	private const ALLOWED_ORDER_FIELDS = [
		'CURRENCY' => true,
		'SORT' => true,
		'CURRENT_LANG_FORMAT.FULL_NAME' => true,
	];

	private const ALLOWED_ORDER_DIRECTIONS = [
		'ASC' => true,
		'DESC' => true,
	];

	private const DEFAULT_ORDER = [
		'SORT' => 'ASC',
		'CURRENCY' => 'ASC',
	];

	private const SELECT = [
		'CURRENCY',
		'FULL_NAME',
		'SORT',
		'AMOUNT',
		'AMOUNT_CNT',
		'BASE',
		'CURRENT_BASE_RATE',
	];

	private CurrencyRepository $repository;

	/**
	 * @param array<string, string> $columnNames
	 */
	public function __construct(
		Settings $settings,
		private readonly string $editUrlTemplate,
		private readonly string $baseCurrencyId,
		private readonly string $accountingCurrencyId,
		private readonly CurrencyMutationService $mutationService,
		private readonly LegacyCurrencyMutationAdapter $mutationAdapter,
		?CurrencyRepository $repository = null,
		private readonly array $columnNames = [],
		private readonly string $setBaseActionText = '',
	)
	{
		parent::__construct($settings);

		$this->repository = $repository ?? new CurrencyRepository();
	}

	public function getComponentParams(array $additionalParams = []): array
	{
		$pagination = $this->getPagination();
		$pagination->setRecordCount($this->getCurrenciesCount());
		$lastPage = max(1, $pagination->getPageCount());
		if ($pagination->getCurrentPage() > $lastPage)
		{
			$pagination->setCurrentPage($lastPage);
		}

		$this->setRawRows(
			$this->getCurrencies(
				$this->getOrmOrder(),
				$pagination->getLimit(),
				$pagination->getOffset(),
			),
		);

		return ComponentParams::get($this, $additionalParams);
	}

	public function getOrmSelect(): array
	{
		return self::SELECT;
	}

	public function getOrmOrder(): array
	{
		$normalizedOrder = [];
		foreach ($this->getRequestedOrder() as $field => $direction)
		{
			$direction = strtoupper((string)$direction);
			if (!isset(self::ALLOWED_ORDER_FIELDS[$field], self::ALLOWED_ORDER_DIRECTIONS[$direction]))
			{
				continue;
			}

			$normalizedOrder[$field] = $direction;
		}

		if ($normalizedOrder === [])
		{
			return self::DEFAULT_ORDER;
		}

		if (!isset($normalizedOrder['CURRENCY']))
		{
			$normalizedOrder['CURRENCY'] = 'ASC';
		}

		return $normalizedOrder;
	}

	protected function createColumns(): Columns
	{
		return new Columns(new CurrencyDataProvider($this->columnNames));
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new CurrencyRowAssembler(
				$this->getVisibleColumnsIds(),
				$this->editUrlTemplate,
				$this->accountingCurrencyId,
			),
			new CurrencyRowActionDataProvider(
				$this->getSettings(),
				$this->editUrlTemplate,
				$this->baseCurrencyId,
				$this->accountingCurrencyId,
				$this->mutationService,
				$this->mutationAdapter,
				$this->setBaseActionText,
			),
		);
	}

	protected function createPanel(): ?Panel
	{
		return new Panel(
			new CurrencyPanelActionDataProvider(
				$this->getSettings(),
				$this->mutationService,
			),
		);
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, null))->create();
	}

	/**
	 * @return array<string, string>
	 */
	protected function getRequestedOrder(): array
	{
		$sorting = $this->getOptions()->getSorting([
			'sort' => self::DEFAULT_ORDER,
		]);

		return is_array($sorting['sort'] ?? null) ? $sorting['sort'] : [];
	}

	protected function getCurrenciesCount(): int
	{
		return $this->repository->getCount();
	}

	/**
	 * @param array<string, string> $order
	 * @return list<array<string, mixed>>
	 */
	protected function getCurrencies(array $order, int $limit, int $offset): array
	{
		return $this->repository->getList($order, $limit, $offset);
	}
}
