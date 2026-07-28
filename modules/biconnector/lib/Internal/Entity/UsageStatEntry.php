<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class UsageStatEntry implements EntityInterface
{
	private ?int $id = null;
	private ?DateTime $timestamp = null;
	private ?int $keyId = null;
	private ?string $serviceId = null;
	private ?string $sourceId = null;
	private ?string $fields = null;
	private ?string $filters = null;
	private ?string $input = null;
	private ?string $requestMethod = null;
	private ?string $requestUri = null;
	private ?int $rowNum = null;
	private ?int $dataSize = null;
	private ?float $realTime = null;
	private bool $isOverLimit = false;
	private ?string $source = null;
	private ?int $externalDashboardId = null;
	private ?string $externalDashboardName = null;
	private ?string $externalChartId = null;
	private ?string $externalChartName = null;
	private ?int $externalDatasetId = null;
	private ?string $externalDatasetName = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getTimestamp(): ?DateTime
	{
		return $this->timestamp;
	}

	public function setTimestamp(?DateTime $timestamp): self
	{
		$this->timestamp = $timestamp;

		return $this;
	}

	public function getKeyId(): ?int
	{
		return $this->keyId;
	}

	public function setKeyId(?int $keyId): self
	{
		$this->keyId = $keyId;

		return $this;
	}

	public function getServiceId(): ?string
	{
		return $this->serviceId;
	}

	public function setServiceId(?string $serviceId): self
	{
		$this->serviceId = $serviceId;

		return $this;
	}

	public function getSourceId(): ?string
	{
		return $this->sourceId;
	}

	public function setSourceId(?string $sourceId): self
	{
		$this->sourceId = $sourceId;

		return $this;
	}

	public function getFields(): ?string
	{
		return $this->fields;
	}

	public function setFields(?string $fields): self
	{
		$this->fields = $fields;

		return $this;
	}

	public function getFilters(): ?string
	{
		return $this->filters;
	}

	public function setFilters(?string $filters): self
	{
		$this->filters = $filters;

		return $this;
	}

	public function getInput(): ?string
	{
		return $this->input;
	}

	public function setInput(?string $input): self
	{
		$this->input = $input;

		return $this;
	}

	public function getRequestMethod(): ?string
	{
		return $this->requestMethod;
	}

	public function setRequestMethod(?string $requestMethod): self
	{
		$this->requestMethod = $requestMethod;

		return $this;
	}

	public function getRequestUri(): ?string
	{
		return $this->requestUri;
	}

	public function setRequestUri(?string $requestUri): self
	{
		$this->requestUri = $requestUri;

		return $this;
	}

	public function getRowNum(): ?int
	{
		return $this->rowNum;
	}

	public function setRowNum(?int $rowNum): self
	{
		$this->rowNum = $rowNum;

		return $this;
	}

	public function getDataSize(): ?int
	{
		return $this->dataSize;
	}

	public function setDataSize(?int $dataSize): self
	{
		$this->dataSize = $dataSize;

		return $this;
	}

	public function getRealTime(): ?float
	{
		return $this->realTime;
	}

	public function setRealTime(?float $realTime): self
	{
		$this->realTime = $realTime;

		return $this;
	}

	public function isOverLimit(): bool
	{
		return $this->isOverLimit;
	}

	public function setIsOverLimit(bool $isOverLimit): self
	{
		$this->isOverLimit = $isOverLimit;

		return $this;
	}

	public function getSource(): ?string
	{
		return $this->source;
	}

	public function setSource(?string $source): self
	{
		$this->source = $source;

		return $this;
	}

	public function getExternalDashboardId(): ?int
	{
		return $this->externalDashboardId;
	}

	public function setExternalDashboardId(?int $dashboardId): self
	{
		$this->externalDashboardId = $dashboardId;

		return $this;
	}

	public function getExternalDashboardName(): ?string
	{
		return $this->externalDashboardName;
	}

	public function setExternalDashboardName(?string $dashboardName): self
	{
		$this->externalDashboardName = $dashboardName;

		return $this;
	}

	public function getExternalChartId(): ?string
	{
		return $this->externalChartId;
	}

	public function setExternalChartId(?string $chartId): self
	{
		$this->externalChartId = $chartId;

		return $this;
	}

	public function getExternalChartName(): ?string
	{
		return $this->externalChartName;
	}

	public function setExternalChartName(?string $chartName): self
	{
		$this->externalChartName = $chartName;

		return $this;
	}

	public function getExternalDatasetId(): ?int
	{
		return $this->externalDatasetId;
	}

	public function setExternalDatasetId(?int $datasetId): self
	{
		$this->externalDatasetId = $datasetId;

		return $this;
	}

	public function getExternalDatasetName(): ?string
	{
		return $this->externalDatasetName;
	}

	public function setExternalDatasetName(?string $datasetName): self
	{
		$this->externalDatasetName = $datasetName;

		return $this;
	}
}
