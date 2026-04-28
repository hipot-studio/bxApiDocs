<?php

namespace Bitrix\BIConnector\DataSource;

abstract class DatasetField
{
	protected const TYPE = null;
	protected bool $isPrimary = false;
	protected bool $isMultiple = false;
	protected bool $isSystem = true;
	protected bool $isDeprecated = false;
	protected bool $metric = false;
	protected string $separator = ', ';
	protected mixed $callback = null;
	protected ?string $description = null;
	protected ?string $descriptionFull = null;
	protected ?JoinSelection $join = null;
	protected ?string $expression = null;
	protected bool $isPreparedFieldName = true;

	public function __construct(
		protected readonly string $code,
		protected ?string $name = null,
		protected ?Dataset $dataset = null,
	)
	{
	}

	private function getMessageCode(): ?string
	{
		return $this->dataset ? $this->dataset->getFieldNamePrefix() . $this->code : null;
	}

	private function getDescription(): string
	{
		if ($this->description !== null)
		{
			return $this->description;
		}

		$phraseCode = $this->getMessageCode();
		if (!$phraseCode)
		{
			return $this->code;
		}

		return $this->dataset->getMessage($phraseCode, $this->code);
	}

	private function getDescriptionFull(): string
	{
		if ($this->descriptionFull !== null)
		{
			return $this->descriptionFull;
		}

		$phraseCode = $this->getMessageCode();
		if (!$phraseCode)
		{
			return '';
		}
		$phraseCode .= '_FULL';

		return $this->dataset->getMessage($phraseCode);
	}

	protected function getName(): string
	{
		if ($this->isDeprecated)
		{
			return 'NULL';
		}

		if ($this->expression !== null)
		{
			return $this->expression;
		}

		if (!empty($this->name))
		{
			return $this->dataset?->getSqlHelper()->quote($this->name) ?? $this->name;
		}

		$aliasCode = $this->dataset?->getAliasFieldName($this->code);
		if (!empty($aliasCode))
		{
			return $aliasCode;
		}

		return $this->dataset?->getSqlHelper()->quote($this->code) ?? $this->code;
	}

	/**
	 * Add custom selection name
	 *
	 * @param string $name
	 *
	 * @return $this
	 */
	public function setName(string $name): static
	{
		$this->name = $name;

		return $this;
	}

	/**
	 * @param Dataset $dataset
	 * @return $this
	 */
	public function setDataset(Dataset $dataset): static
	{
		$this->dataset = $dataset;

		return $this;
	}

	/**
	 * @param JoinSelection $join
	 * @return $this
	 */
	public function setJoin(JoinSelection $join): static
	{
		$this->join = $join;

		return $this;
	}

	/**
	 * Set raw expression for field selection.
	 *
	 * @param string $expression
	 * @param bool $isPrepared
	 * 
	 * @return $this
	 */
	public function setExpression(string $expression, bool $isPrepared = true): static
	{
		$this->expression = $expression;
		$this->isPreparedFieldName = $isPrepared;

		return $this;
	}

	/**
	 * Mark field as primary. It`s false by default.
	 *
	 * @param bool $isPrimary
	 * @return $this
	 */
	public function setPrimary(bool $isPrimary = true): static
	{
		$this->isPrimary = $isPrimary;

		return $this;
	}

	/**
	 * Mark field as `system` to differentiate from user fields. It`s true by default.
	 *
	 * @param bool $isSystem
	 * @return $this
	 */
	public function setSystem(bool $isSystem = true): static
	{
		$this->isSystem = $isSystem;

		return $this;
	}

	/**
	 * Mark field as multiple for concatenation field values. It`s false by default
	 *
	 * @param bool $multiple
	 * @return $this
	 */
	public function setMultiple(bool $multiple = true): static
	{
		$this->isMultiple = $multiple;

		return $this;
	}

	/**
	 * Mark field as metric. It`s false by default
	 *
	 * @param bool $metric
	 * @return $this
	 */
	public function setMetric(bool $metric = true): static
	{
		$this->metric = $metric;

		return $this;
	}

	/**
	 * Mark field as deprecated. Return `NULL` instead of deprecated field. Made for compatibility with old selections.
	 *
	 * @param bool $deprecated
	 * @return $this
	 */
	public function setDeprecated(bool $deprecated = true): static
	{
		$this->isDeprecated = $deprecated;

		return $this;
	}

	/**
	 * Set separator for concatenation field values. It`s comma by default
	 *
	 * @param string $separator
	 * @return $this
	 */
	public function setSeparator(string $separator): static
	{
		$this->separator = $separator;

		return $this;
	}

	/**
	 * Set separator for concatenation field values. It`s comma by default
	 *
	 * @param string $separator
	 * @return $this
	 */
	public function setCallback(callable $callback): static
	{
		$this->callback = $callback;

		return $this;
	}

	/**
	 * Set field description
	 *
	 * @param string $description
	 * @return $this
	 */
	public function setDescription(string $description): static
	{
		$this->description = $description;

		return $this;
	}

	/**
	 * Set field description
	 *
	 * @param string $descriptionFull
	 * @return $this
	 */
	public function setDescriptionFull(string $descriptionFull): static
	{
		$this->descriptionFull = $descriptionFull;

		return $this;
	}

	/**
	 * Return field code
	 *
	 * @return string
	 */
	public function getCode(): string
	{
		return $this->code;
	}

	/**
	 * Return prepared fields
	 *
	 * @return array
	 */
	public function getFormatted(): array
	{
		$result = [
			'FIELD_NAME' => $this->getName(),
			'FIELD_TYPE' => static::TYPE,
			'FIELD_DESCRIPTION' => $this->getDescription(),
			'FIELD_DESCRIPTION_FULL' => $this->getDescriptionFull(),
			'IS_SYSTEM' => $this->isSystem ? 'Y' : 'N',
			'IS_FIELD_NAME_PREPARED' => $this->isPreparedFieldName ? 'Y' : 'N',
		];

		if ($this->isPrimary)
		{
			$result['IS_PRIMARY'] = 'Y';
		}

		if ($this->metric)
		{
			$result['IS_METRIC'] = 'Y';
		}

		if ($this->join)
		{
			$result += $this->join->toArray();
		}

		if ($this->isMultiple)
		{
			$result['GROUP_KEY'] = $this->code;
			$result['GROUP_CONCAT'] = $this->separator;
		}

		if ($this->callback !== null)
		{
			$result['CALLBACK'] = $this->callback;
		}

		return $result;
	}
}
