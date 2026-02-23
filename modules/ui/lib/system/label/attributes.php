<?php declare(strict_types=1);

namespace Bitrix\UI\System\Label;

final class Attributes
{
	private array $attributes = [];
	private array $dataAttributes = [];
	private array $classList = [];

	public function __toString(): string
	{
		$attributes = $this->attributes;

		if (!empty($this->classList))
		{
			$attributes['class'] = $this->classList;
		}

		foreach ($this->dataAttributes as $name => $value)
		{
			$attributes["data-{$name}"] = $value;
		}

		$string = '';
		foreach ($attributes as $key => $value)
		{
			if ($key === 'class')
			{
				$value = $this->convertClassesToString($value);
			}
			elseif ($key === 'style')
			{
				$value = $this->convertStylesToString($value);
			}

			$value = htmlspecialcharsbx((string)$value);
			$string .= "{$key}=\"{$value}\" ";
		}

		return $string;
	}

	public function addClass(string $className): self
	{
		$normalized = $this->normalizeClassList($className);
		foreach ($normalized as $class)
		{
			if (!in_array($class, $this->classList, true))
			{
				$this->classList[] = $class;
			}
		}

		return $this;
	}

	public function setClassList(array|string $classList): self
	{
		$this->classList = $this->normalizeClassList($classList);

		return $this;
	}

	public function getClassList(): array
	{
		return $this->classList;
	}

	public function addDataAttribute(string $name, $value = null): self
	{
		if ($name !== '')
		{
			$this->dataAttributes[$name] = $value;
		}

		return $this;
	}

	public function setAttribute(string $name, $value = null): self
	{
		$name = mb_strtolower($name);
		if ($name === 'class')
		{
			if (is_array($value) || is_string($value))
			{
				$this->setClassList($value);
			}

			return $this;
		}

		$this->attributes[$name] = $value;

		return $this;
	}

	private function convertClassesToString(string|array $classes): string
	{
		if (is_string($classes))
		{
			return $classes;
		}

		return implode(' ', $classes);
	}

	private function convertStylesToString(string|array $styles): string
	{
		if (is_string($styles))
		{
			return $styles;
		}

		$string = '';
		if (is_array($styles))
		{
			foreach ($styles as $name => $value)
			{
				$string .= "{$name}:{$value};";
			}
		}

		return $string;
	}

	private function normalizeClassList(array|string $classList): array
	{
		if (is_string($classList))
		{
			$classList = preg_split('/\s+/', trim($classList)) ?: [];
		}

		$normalized = [];
		foreach ($classList as $className)
		{
			if (is_string($className))
			{
				$className = trim($className);
				if ($className !== '')
				{
					$normalized[] = $className;
				}
			}
		}

		return array_values(array_unique($normalized));
	}
}

