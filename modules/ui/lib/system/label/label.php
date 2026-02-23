<?php

declare(strict_types=1);

namespace Bitrix\UI\System\Label;

use Bitrix\Main\UI\Extension;

final class Label
{
	private const TAG = 'div';

	private string $value = '';
	private Style $style = Style::FILLED;
	private Size $size = Size::MD;
	private ?Icon $icon = null;
	private bool $bordered = false;
	private ?string $title = null;
	private Attributes $attributes;

	public function __construct(array $params = [])
	{
		$this->attributes = new Attributes();
		$this->buildFromArray($params);
	}

	public static function create(array $params = []): self
	{
		return new self($params);
	}

	public function render(): string
	{
		Extension::load('ui.system.label');

		$attributes = clone $this->attributes;
		$attributes->setClassList($this->buildClassList());

		if ($this->title !== null)
		{
			$attributes->setAttribute('title', $this->title);
		}

		$tag = self::TAG;

		return sprintf(
			'<%s %s>%s</%s>',
			$tag,
			$attributes,
			$this->renderInner(),
			$tag,
		);
	}

	public function setValue(string $value): self
	{
		$this->value = $value;

		return $this;
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function setStyle(Style $style): self
	{
		$this->style = $style;

		return $this;
	}

	public function setSize(Size $size): self
	{
		$this->size = $size;

		return $this;
	}

	public function setIcon(?Icon $icon): self
	{
		$this->icon = $icon === Icon::NONE ? null : $icon;

		return $this;
	}

	public function setBordered(bool $flag = true): self
	{
		$this->bordered = $flag;

		return $this;
	}

	public function addClass(string $className): self
	{
		if ($className !== '')
		{
			$this->attributes->addClass($className);
		}

		return $this;
	}

	public function addClasses(array $classList): self
	{
		foreach ($classList as $className)
		{
			if (is_string($className) && $className !== '')
			{
				$this->attributes->addClass($className);
			}
		}

		return $this;
	}

	public function addDataAttribute(string $name, $value = null): self
	{
		$this->attributes->addDataAttribute($name, $value);

		return $this;
	}

	public function setAttribute(string $name, $value = null): self
	{
		if (mb_strtolower($name) === 'class')
		{
			if (is_array($value))
			{
				$this->addClasses($value);
			}
			else
			{
				$this->addClassListFromString((string)$value);
			}

			return $this;
		}

		$this->attributes->setAttribute($name, $value);

		return $this;
	}

	private function renderInner(): string
	{
		return sprintf(
			'<div class="ui-system-label__inner"><div class="ui-system-label__value">%s</div></div>',
			htmlspecialcharsbx($this->value),
		);
	}

	private function buildClassList(): array
	{
		$classes = [
			'ui-system-label',
			'--size-' . $this->size->value,
			'--style-' . $this->style->value,
		];

		if ($this->bordered)
		{
			$classes[] = '--bordered';
		}

		if ($this->icon)
		{
			$classes[] = '--icon-mode';
			$classes[] = '--icon-' . $this->icon->value;
		}

		foreach ($this->attributes->getClassList() as $className)
		{
			if ($className !== '')
			{
				$classes[] = $className;
			}
		}

		return array_values(array_unique($classes));
	}

	private function addClassListFromString(string $className): void
	{
		$classList = preg_split('/\s+/', trim($className));
		if (!is_array($classList))
		{
			return;
		}

		foreach ($classList as $class)
		{
			if ($class !== '')
			{
				$this->attributes->addClass($class);
			}
		}
	}

	private function buildFromArray(array $params): void
	{
		if (isset($params['value']))
		{
			$this->setValue((string)$params['value']);
		}

		if (array_key_exists('style', $params) && $params['style'] instanceof Style)
		{
			$this->setStyle($params['style']);
		}

		if (array_key_exists('size', $params) && $params['size'] instanceof Size)
		{
			$this->setSize($params['size']);
		}

		if (array_key_exists('icon', $params) && $params['icon'] instanceof Icon)
		{
			$this->setIcon($params['icon']);
		}

		if (isset($params['bordered']))
		{
			$this->setBordered((bool)$params['bordered']);
		}

		if (array_key_exists('title', $params))
		{
			$this->setTitle($params['title']);
		}

		if (!empty($params['className']))
		{
			$this->addClassListFromString((string)$params['className']);
		}

		if (!empty($params['classList']) && is_array($params['classList']))
		{
			$this->addClasses($params['classList']);
		}

		if (!empty($params['dataset']) && is_array($params['dataset']))
		{
			foreach ($params['dataset'] as $name => $value)
			{
				$this->addDataAttribute((string)$name, $value);
			}
		}

		if (!empty($params['attributes']) && is_array($params['attributes']))
		{
			foreach ($params['attributes'] as $name => $value)
			{
				$this->setAttribute((string)$name, $value);
			}
		}
	}

	// Only enum values are supported for style/size/icon.
}
