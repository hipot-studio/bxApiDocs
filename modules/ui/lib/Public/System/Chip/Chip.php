<?php

declare(strict_types=1);

namespace Bitrix\UI\Public\System\Chip;

use Bitrix\Main\Security\Random;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class Chip
{
	private string $text = '';
	private Design $design = Design::OUTLINE;
	private Size $size = Size::LG;
	private ?string $icon = null;
	private ?string $iconColor = null;
	private ?string $iconBackground = null;
	private ?string $imageSrc = null;
	private ?string $imageAlt = null;
	private bool $rounded = false;
	private bool $withClear = false;
	private bool $dropdown = false;
	private bool $lock = false;
	private bool $compact = true;
	private bool $trimmable = false;
	private ?string $onClick = null;
	private ?string $onClear = null;

	public function __construct(array $params = [])
	{
		$this->buildFromArray($params);
	}

	public static function create(array $params = []): self
	{
		return new self($params);
	}

	public function render(): string
	{
		Extension::load('ui.system.chip');

		$needsJs = $this->needsJavascript();
		$uniqId = $needsJs ? 'ui-chip-' . Random::getString(6) : null;

		$cssClasses = 'ui-chip ' . $this->buildModifierClasses();
		$innerHtml = $this->renderIcon() . $this->renderText();

		if ($this->dropdown)
		{
			$innerHtml .= '<div class="ui-chip-right-icon ui-icon-set --' . Outline::CHEVRON_DOWN_M->value . '"></div>';
		}

		if ($this->withClear)
		{
			$innerHtml .= '<div class="ui-chip-right-icon ui-icon-set --' . Outline::CROSS_M->value . '"></div>';
		}

		if ($this->lock)
		{
			$innerHtml .= '<div class="ui-chip-lock ui-icon-set --' . Outline::LOCK_M->value . '"></div>';
		}

		$dataAttr = $uniqId !== null
			? sprintf(' data-uniqid="%s"', htmlspecialcharsbx($uniqId))
			: '';

		$output = sprintf(
			'<div class="%s" tabindex="0"%s>%s</div>',
			htmlspecialcharsbx($cssClasses),
			$dataAttr,
			$innerHtml,
		);

		if ($uniqId !== null)
		{
			$js = $this->renderJavascript($uniqId);
			$output .= sprintf('<script>(function(){%s})();</script>', $js);
		}

		return $output;
	}

	private function buildModifierClasses(): string
	{
		$classes = [
			'--' . $this->design->value,
			'--' . $this->size->value,
		];

		if ($this->rounded)
		{
			$classes[] = '--rounded';
		}

		if ($this->compact)
		{
			$classes[] = '--compact';
		}

		if ($this->trimmable)
		{
			$classes[] = '--trimmable';
		}

		if ($this->withClear || $this->dropdown)
		{
			$classes[] = '--with-right-icon';
		}

		if ($this->text === '')
		{
			$classes[] = '--no-text';
		}

		return implode(' ', $classes);
	}

	private function renderIcon(): string
	{
		if ($this->imageSrc !== null)
		{
			return sprintf(
				'<img class="ui-chip-icon --image" src="%s" alt="%s">',
				htmlspecialcharsbx($this->imageSrc),
				htmlspecialcharsbx($this->imageAlt ?? ''),
			);
		}

		if ($this->icon !== null)
		{
			$containerStyle = '';
			$extraClass = '';

			if ($this->iconBackground !== null)
			{
				$extraClass = ' --with-background';
				$containerStyle = sprintf(
					' style="--icon-background:%s"',
					htmlspecialcharsbx($this->iconBackground),
				);
			}

			$iconStyle = '';
			if ($this->iconColor !== null)
			{
				$iconStyle = sprintf(
					' style="--ui-icon-set__icon-color:%s"',
					htmlspecialcharsbx($this->iconColor),
				);
			}

			return sprintf(
				'<div class="ui-chip-icon%s"%s>'
				. '<div class="ui-icon-set --%s"%s></div>'
				. '</div>',
				$extraClass,
				$containerStyle,
				htmlspecialcharsbx($this->icon),
				$iconStyle,
			);
		}

		return '';
	}

	private function renderText(): string
	{
		return sprintf(
			'<div class="ui-chip-text">%s</div>',
			htmlspecialcharsbx($this->text),
		);
	}

	private function needsJavascript(): bool
	{
		return $this->onClick !== null
			|| $this->onClear !== null;
	}

	private function renderJavascript(string $uniqId): string
	{
		$parts = [];
		$selector = Json::encode('.ui-chip[data-uniqid="' . $uniqId . '"]');
		$parts[] = sprintf('var w=document.querySelector(%s);if(!w){return;}', $selector);

		if ($this->onClick !== null)
		{
			$parts[] = sprintf(
				'BX.bind(w,"click",function(e){%s});',
				$this->onClick,
			);
			$parts[] = sprintf(
				'BX.bind(w,"keydown",function(e){'
				. 'if(e.key==="Enter"&&!e.ctrlKey&&!e.metaKey){%s}'
				. '});',
				$this->onClick,
			);
		}

		if ($this->onClear !== null)
		{
			$parts[] = sprintf(
				'var cl=w.querySelector(".ui-chip-clear-icon");'
				. 'if(cl){BX.bind(cl,"click",function(e){'
				. 'e.stopPropagation();%s'
				. '});}',
				$this->onClear,
			);
		}

		return implode('', $parts);
	}

	public function setText(string $text): self
	{
		$this->text = $text;

		return $this;
	}

	public function setDesign(Design $design): self
	{
		$this->design = $design;

		return $this;
	}

	public function setSize(Size $size): self
	{
		$this->size = $size;

		return $this;
	}

	public function setIcon(?string $icon): self
	{
		$this->icon = $icon;

		return $this;
	}

	public function setIconColor(?string $iconColor): self
	{
		$this->iconColor = $iconColor;

		return $this;
	}

	public function setIconBackground(?string $iconBackground): self
	{
		$this->iconBackground = $iconBackground;

		return $this;
	}

	public function setImage(?string $src, ?string $alt = null): self
	{
		$this->imageSrc = $src;
		$this->imageAlt = $alt;

		return $this;
	}

	public function setRounded(bool $rounded = true): self
	{
		$this->rounded = $rounded;

		return $this;
	}

	public function setWithClear(bool $withClear = true): self
	{
		$this->withClear = $withClear;

		return $this;
	}

	public function setDropdown(bool $dropdown = true): self
	{
		$this->dropdown = $dropdown;

		return $this;
	}

	public function setLock(bool $lock = true): self
	{
		$this->lock = $lock;

		return $this;
	}

	public function setCompact(bool $compact = true): self
	{
		$this->compact = $compact;

		return $this;
	}

	public function setTrimmable(bool $trimmable = true): self
	{
		$this->trimmable = $trimmable;

		return $this;
	}

	public function setOnClick(string $jsExpression): self
	{
		$this->onClick = $jsExpression;

		return $this;
	}

	public function setOnClear(string $jsExpression): self
	{
		$this->onClear = $jsExpression;

		return $this;
	}

	private function buildFromArray(array $params): void
	{
		if (isset($params['text']))
		{
			$this->setText((string)$params['text']);
		}

		if (isset($params['design']) && $params['design'] instanceof Design)
		{
			$this->setDesign($params['design']);
		}

		if (isset($params['size']) && $params['size'] instanceof Size)
		{
			$this->setSize($params['size']);
		}

		if (isset($params['icon']))
		{
			$this->setIcon((string)$params['icon']);
		}

		if (isset($params['iconColor']))
		{
			$this->setIconColor((string)$params['iconColor']);
		}

		if (isset($params['iconBackground']))
		{
			$this->setIconBackground((string)$params['iconBackground']);
		}

		if (isset($params['image']) && is_array($params['image']))
		{
			$this->setImage(
				(string)($params['image']['src'] ?? ''),
				isset($params['image']['alt']) ? (string)$params['image']['alt'] : null,
			);
		}

		if (isset($params['rounded']))
		{
			$this->setRounded((bool)$params['rounded']);
		}

		if (isset($params['withClear']))
		{
			$this->setWithClear((bool)$params['withClear']);
		}

		if (isset($params['dropdown']))
		{
			$this->setDropdown((bool)$params['dropdown']);
		}

		if (isset($params['lock']))
		{
			$this->setLock((bool)$params['lock']);
		}

		if (isset($params['compact']))
		{
			$this->setCompact((bool)$params['compact']);
		}

		if (isset($params['trimmable']))
		{
			$this->setTrimmable((bool)$params['trimmable']);
		}

		if (isset($params['onClick']))
		{
			$this->setOnClick((string)$params['onClick']);
		}

		if (isset($params['onClear']))
		{
			$this->setOnClear((string)$params['onClear']);
		}

	}
}
