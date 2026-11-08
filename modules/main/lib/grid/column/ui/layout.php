<?php

namespace Bitrix\Main\Grid\Column\UI;

use Bitrix\Main\Grid\Column\Column;
use Bitrix\Main\Html\Attributes;

/**
 * Layout of the column and its cells.
 */
final class Layout
{
	private Column $column;
	private bool $hasLeftAlignedCounter = false;
	private bool $sortable = true;

	/**
	 * @param Column $column
	 */
	public function __construct(Column $column)
	{
		$this->column = $column;
	}

	/**
	 * Sets has counter with left align.
	 *
	 * @param bool $value
	 *
	 * @return self
	 */
	public function setHasLeftAlignedCounter(bool $value): self
	{
		$this->hasLeftAlignedCounter = $value;

		return $this;
	}

	/**
	 * Has counter with left align?
	 *
	 * @return bool
	 */
	public function isHasLeftAlignedCounter(): bool
	{
		return $this->hasLeftAlignedCounter;
	}

	/**
	 * Sets whether sorting by this column is allowed in the grid.
	 *
	 * @param bool $value
	 *
	 * @return self
	 */
	public function setSortable(bool $value): self
	{
		$this->sortable = $value;

		return $this;
	}

	/**
	 * Is sorting by this column allowed?
	 *
	 * @return bool
	 */
	public function isSortable(): bool
	{
		return $this->sortable && $this->column->getSort() !== null;
	}

	/**
	 * Columns cells attributes.
	 *
	 * @return Attributes
	 */
	public function getCellAttributes(): Attributes
	{
		$result = new Attributes();

		$result->set('scope', 'col');

		$value = $this->column->getTitle();
		if (!empty($value))
		{
			$result->set('title', $value);
		}

		$value = $this->column->getCssColorClassName();
		if (!empty($value))
		{
			$result->set('class', $value);
		}

		$value = $this->getColumnStyle();
		if (!empty($value))
		{
			$result->set('style', $value);
		}

		if ($this->isSortable())
		{
			$result->set('aria-sort', $this->getAriaSortValue());
		}

		$result->setData('name', $this->column->getId());
		$result->setData('sort-by', $this->column->getSort());
		$result->setData('sort-url', $this->column->getSortUrl());
		$result->setData('sort-order', $this->column->getNextSortOrder());

		$editable = $this->column->getEditable();
		if (isset($editable))
		{
			$result->setData('edit', $editable->toArray());
		}
		else
		{
			$result->setData('edit', false);
		}

		return $result;
	}

	/**
	 * Current sort direction of the column in aria-sort terms.
	 *
	 * @return string
	 */
	private function getAriaSortValue(): string
	{
		// setSortState() narrows the value down to 'asc', 'desc' or null.
		return match ($this->column->getSortState())
		{
			'asc' => 'ascending',
			'desc' => 'descending',
			default => 'none',
		};
	}

	/**
	 * Column style attribute value.
	 *
	 * @return string
	 */
	private function getColumnStyle(): string
	{
		$result = '';

		$width = $this->column->getWidth();
		if (isset($width))
		{
			$result .= " width:{$width}px;";

			// A width the user dragged the column to has to beat whatever a module's own
			// min-width or an unbreakable word in a cell asks for - the same three properties
			// the drag itself writes on every cell of the column. Any other width is a start
			// width that the content may widen.
			if ($this->column->isResizedByUser())
			{
				$result .= " min-width:{$width}px; max-width:{$width}px;";
			}
		}

		$colorValue = $this->column->getCssColorValue();
		if (isset($colorValue))
		{
			$result .= " background-color:{$colorValue};";
		}

		return trim($result);
	}

	/**
	 * Column container attributes.
	 *
	 * @return Attributes
	 */
	public function getContainerAttributes(): Attributes
	{
		$result = new Attributes();

		$value = $this->getContainerStyle();
		if (!empty($value))
		{
			$result->set('style', $value);
		}

		return $result;
	}

	/**
	 * Column container style attribute value.
	 *
	 * @return string
	 */
	private function getContainerStyle(): string
	{
		$result = '';

		$width = $this->column->getWidth();
		if (isset($width))
		{
			// Width only: the drag resizes this container by setting its width, and a min/max
			// pair rendered for the previous width would pin it - the resize handle lives
			// inside it and would stay behind at the old column border.
			$result .= "width:{$width}px;";
		}

		return $result;
	}
}
