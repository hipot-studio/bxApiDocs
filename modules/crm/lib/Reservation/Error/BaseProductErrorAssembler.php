<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Error;

use Bitrix\Main;

class BaseProductErrorAssembler
{
	public const TEMPLATE_PRODUCT_ONE = 'OneProduct';
	public const TEMPLATE_PRODUCT_MULTI = 'MultipleProducts';
	public const TEMPLATE_PRODUCT_TOO_MANY = 'TooManyProducts';

	public const COMMON_PRODUCT_ERROR_CODE = 'PRODUCT_ERROR';

	public const CUSTOM_DATA_PRODUCT_KEY = 'PRODUCT_IDS';

	protected const DEFAULT_TOO_MANY_PRODUCT_LIMIT = 3;

	protected const DEFAULT_NAME_TEMPLATE = '[#ID#] #NAME#';

	protected int $tooManyProductLimit;
	protected int|string $errorCode;
	protected null|array $additionalErrorCustomData;
	protected array $errorTemplates;
	protected array $productNames;
	protected string $nameTemplate;

	public function __construct()
	{
		$this
			->setTooManyProductLimit()
			->setErrorCode()
			->setAdditionalErrorCustomData()
			->setErrorTemplates([])
			->setProductNames([])
			->setNameTemplate(self::DEFAULT_NAME_TEMPLATE)
		;
	}

	public function __destruct()
	{
		unset(
			$this->tooManyProductLimit,
			$this->errorCode,
			$this->additionalErrorCustomData,
			$this->errorTemplates,
			$this->productNames,
			$this->nameTemplate,
		);
	}

	public function setTooManyProductLimit(int $tooManyProductLimit = self::DEFAULT_TOO_MANY_PRODUCT_LIMIT): static
	{
		$this->tooManyProductLimit = $tooManyProductLimit;

		return $this;
	}

	public function getTooManyProductLimit(): int
	{
		return $this->tooManyProductLimit;
	}

	public function setErrorCode(int|string $errorCode = self::COMMON_PRODUCT_ERROR_CODE): static
	{
		$this->errorCode = $errorCode;

		return $this;
	}

	public function getErrorCode(): int|string
	{
		return $this->errorCode;
	}

	public function setAdditionalErrorCustomData(null|array $additionalErrorCustomData = null): static
	{
		$this->additionalErrorCustomData = $additionalErrorCustomData;

		return $this;
	}

	public function getAdditionalErrorCustomData(): ?array
	{
		return $this->additionalErrorCustomData;
	}

	public function setErrorTemplates(array $errorTemplates): static
	{
		$this->errorTemplates = $errorTemplates;

		return $this;
	}

	public function getErrorTemplates(): array
	{
		return $this->errorTemplates;
	}

	public function setProductNames(array $productNames): static
	{
		$this->productNames = [];

		$productNames = array_filter(
			$productNames,
			fn($name) => is_string($name) && trim($name) !== ''
		);
		foreach ($productNames as $id => $name)
		{
			$this->productNames[] = [
				'ID' => $id,
				'NAME' => $name,
			];
		}

		return $this;
	}

	public function getProductNames(): array
	{
		return $this->productNames;
	}

	public function setNameTemplate(string $nameTemplate): static
	{
		$this->nameTemplate = $nameTemplate;

		return $this;
	}

	public function getNameTemplate(): string
	{
		return $this->nameTemplate;
	}

	public function getFormattedProductName(int $id, string $name): string
	{
		$result = str_replace(
			['#ID#', '#NAME#'],
			[$id, $name],
			$this->getNameTemplate()
		);

		return $result ?: '[' . $id. '] ' . $name;
	}

	public function getError(): Main\Error
	{
		return new Main\Error(
			$this->getErrorMessage(),
			$this->getErrorCode(),
			$this->getErrorCustomData(),
		);
	}

	public function getErrorMessage(): string
	{
		$templateId = $this->getErrorTemplateId();
		if ($templateId === null)
		{
			return '';
		}

		return $this->assembleErrorMessage($templateId, $this->getProductsForTemplate($templateId));

	}

	protected function getErrorTemplateId(): ?string
	{
		$count = count($this->getProductNames());
		if ($count === 0)
		{
			return null;
		}

		if ($count === 1)
		{
			return self::TEMPLATE_PRODUCT_ONE;
		}

		if ($count <= $this->getTooManyProductLimit())
		{
			return self::TEMPLATE_PRODUCT_MULTI;
		}

		return self::TEMPLATE_PRODUCT_TOO_MANY;
	}

	protected function assembleErrorMessage(string $templateId, string $products): string
	{
		$template = $this->getErrorTemplates()[$templateId] ?? null;
		if ($template === null || $products === '')
		{
			return '';
		}

		return strtr($template, ['#PRODUCTS#' => $products]);
	}

	protected function getProductsForTemplate($action): string
	{
		$result = [];
		$count = match($action)
		{
			self::TEMPLATE_PRODUCT_ONE => 1,
			self::TEMPLATE_PRODUCT_MULTI => count($this->getProductNames()),
			self::TEMPLATE_PRODUCT_TOO_MANY => $this->getTooManyProductLimit(),
			default => 0,
		};

		foreach (array_slice($this->getProductNames(), 0, $count) as $row)
		{
			$result[] = $this->getFormattedProductName($row['ID'], $row['NAME']);
		}

		return implode(', ', $result);
	}

	protected function getErrorCustomData(): array
	{
		$productNames = $this->getProductNames();

		$result = [
			self::CUSTOM_DATA_PRODUCT_KEY => $productNames ? array_column($productNames, 'ID') : [],
		];

		$additional = $this->getAdditionalErrorCustomData();
		if ($additional !== null)
		{
			$result = [...$result, ...$additional];
		}

		return $result;
	}
}
