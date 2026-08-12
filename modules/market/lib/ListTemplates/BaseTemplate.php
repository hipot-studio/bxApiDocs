<?php


namespace Bitrix\Market\ListTemplates;


use Bitrix\Market\Rest\Actions;

abstract class BaseTemplate
{
	protected array $result = [];

	protected array $filter = [];

	protected array $order = [];

	protected int $page = 1;

	protected array $requestParams = [];

	protected bool $mobileMarketContext = false;

	public function __construct($requestParams = [])
	{
		$this->requestParams = $requestParams;
	}

	abstract public function setResult(bool $isAjax = false);

	public function getInfo(): array
	{
		return $this->result;
	}

	public function setFilter(array $filter): void
	{
		$this->filter = $filter;
	}

	public function setOrder(array $order): void
	{
		$this->order = $order;
	}

	public function setPage(int $page): void
	{
		$this->page = $page;
	}

	public function setMobileMarketContext(bool $mobileMarketContext = true): void
	{
		$this->mobileMarketContext = $mobileMarketContext;
	}

	protected function getMobileMarketContextParams(): array
	{
		if (!$this->mobileMarketContext)
		{
			return [];
		}

		return [
			Actions::PARAM_IS_MOBILE_MARKET => 'Y',
		];
	}
}
