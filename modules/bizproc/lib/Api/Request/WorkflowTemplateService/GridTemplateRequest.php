<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateService;

final class GridTemplateRequest
{
	private int $limit = 0;
	private ?int $offset = null;
	private int $filterUserId = 0;
	private bool $countTotal = false;
	private array $filter = [];
	private ?string $filterSearchQuery = null;
	private array $order = ['MODIFIED' => 'DESC'];

	public function getOrder(): array
	{
		return $this->order;
	}

	public function hasOffset(): bool
	{
		return $this->offset !== null;
	}

	public function setFilterSearchQuery(string $query): static
	{
		$this->filterSearchQuery = $query;

		return $this;
	}

	public function getFilterSearchQuery(): ?string
	{
		return $this->filterSearchQuery;
	}

	public function setOrder(array $order): static
	{
		$this->order = $order;

		return $this;
	}

	public function getFilterUserId(): int
	{
		return $this->filterUserId;
	}

	public function setFilterUserId(int $userId): static
	{
		$this->filterUserId = $userId;

		return $this;
	}

	public function setFilter(array $filter): static
	{
		$this->filter = $filter;

		return $this;
	}

	public function getCountTotal(): bool
	{
		return $this->countTotal;
	}

	public function setCountTotal(bool $count = true): static
	{
		$this->countTotal = $count;

		return $this;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	public function setLimit(int $limit): static
	{
		if ($limit >= 0)
		{
			$this->limit = $limit;
		}

		return $this;
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function setOffset(int $offset): static
	{
		if ($offset >= 0)
		{
			$this->offset = $offset;
		}

		return $this;
	}

	public function getOrmFilter(): array
	{
		return $this->filter;
	}
}