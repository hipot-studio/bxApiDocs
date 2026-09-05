<?php

namespace Bitrix\UI\Buttons;

use Bitrix\Main\UI\Extension;

class Button extends BaseButton
{
	/** @var array  */
	protected $properties = [];
	/** @var bool whether the arrow was added by the system menu setter and may be taken back by it */
	private bool $dropdownBySystemMenu = false;

	protected function init(array $params = [])
	{
		$this->setColor(Color::SUCCESS);

		if (isset($params['baseClassName']))
		{
			$this->baseClass = $params['baseClassName'];
		}

		parent::init($params);
	}

	/**
	 * @param $value
	 * @param $enum
	 *
	 * @return bool
	 */
	protected function isEnumValue($value, $enum)
	{
		try
		{
			return in_array($value, (new \ReflectionClass($enum))->getConstants(), true);
		}
		catch (\ReflectionException $e)
		{}

		return false;
	}

	/**
	 * @param $propertyName
	 * @param $value
	 * @param $enum
	 *
	 * @return $this
	 */
	protected function setProperty($propertyName, $value, $enum)
	{
		if ($this->isEnumValue($value, $enum))
		{
			$this
				->removeClass($this->getProperty($propertyName))
				->addClass($value)
			;
			$this->properties[$propertyName] = $value;
		}
		elseif ($value === null)
		{
			$this->removeClass($this->getProperty($propertyName));
			$this->properties[$propertyName] = $value;
		}

		return $this;
	}

	/**
	 * @param $name
	 * @param null $defaultValue
	 *
	 * @return mixed|null
	 */
	protected function getProperty($name, $defaultValue = null)
	{
		if (isset($this->properties[$name]))
		{
			return $this->properties[$name];
		}

		return $defaultValue;
	}

	protected function buildFromArray($params)
	{
		parent::buildFromArray($params);

		if (isset($params['color']))
		{
			$this->setColor($params['color']);
		}

		if (isset($params['style']))
		{
			$this->setStyle($params['style']);
		}

		if (isset($params['icon']))
		{
			$this->setIcon($params['icon']);
		}

		if (isset($params['collapsedIcon']))
		{
			$this->setCollapsedIcon($params['collapsedIcon']);
		}

		if (isset($params['state']))
		{
			$this->setState($params['state']);
		}

		if (isset($params['size']))
		{
			$this->setSize($params['size']);
		}

		if (isset($params['menu']))
		{
			$this->setMenu($params['menu']);
		}

		if (isset($params['systemMenu']))
		{
			$this->setSystemMenu($params['systemMenu']);
		}

		if (isset($params['noCaps']))
		{
			$this->setNoCaps($params['noCaps']);
		}
		elseif ($this->hasAirDesign())
		{
			$this->setNoCaps();
		}

		if (isset($params['round']))
		{
			$this->setRound($params['round']);
		}

		if (isset($params['collapsed']) && $params['collapsed'] === true)
		{
			$this->setCollapsed();
		}

		$isDropdown = $params['dropdown'] ?? null;
		$hasMenu = isset($params['menu']) || $this->hasSystemMenu();
		if ($isDropdown || ($hasMenu && $isDropdown !== false))
		{
			$this->setDropdown();
		}
		elseif ($isDropdown === false)
		{
			// only the arrow of a system menu is taken back, an arrow class of the caller stays untouched
			if ($this->hasSystemMenu())
			{
				$this->setDropdown(false);
				$this->dropdownBySystemMenu = false;
			}

			$this->getAttributeCollection()->addJsonOption('dropdown', false);
		}
	}

	public function setIcon($icon)
	{
		if (isset($icon))
		{
			$this->addClass('ui-icon-set__scope');
		}
		else
		{
			$this->removeClass('ui-icon-set__scope');
		}

		if ($this->hasAirDesign())
		{
			$this->addClass('--with-left-icon');
		}
		else
		{
			$this->removeClass('--with-left-icon');
		}

		return $this->setProperty('icon', $icon, Icon::class);
	}

	public function setCollapsedIcon($icon): Button
	{
		if (isset($icon))
		{
			$this->addClass('ui-icon-set__scope');
			$this->addClass('--with-collapsed-icon');
		}
		else
		{
			$this->removeClass('ui-icon-set__scope');
			$this->removeClass('--with-collapsed-icon');
		}

		return $this->setProperty('icon', $icon, Icon::class);
	}

	public function hasCollapsedIcon(): bool
	{
		return $this->hasClass('--with-collapsed-icon');
	}

	public function getIcon()
	{
		return $this->getProperty('icon');
	}

	/**
	 * @param string $color
	 * @see Color
	 *
	 * @return Button
	 */
	public function setColor($color)
	{
		return $this->setProperty('color', $color, Color::class);
	}

	/**
	 * @return string|null
	 */
	public function getColor()
	{
		return $this->getProperty('color');
	}

	/**
	 * @param $style
	 * @return $this
	 */
	public function setStyle($style): self
	{
		$this->setProperty('style', $style, AirButtonStyle::class);

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getStyle(): ?string
	{
		return $this->getProperty('style');
	}

	/**
	 * @param $size
	 *
	 * @return Button
	 * @see Size
	 *
	 */
	public function setSize($size)
	{
		return $this->setProperty('size', $size, Size::class);
	}

	/**
	 * @return string|null
	 */
	public function getSize()
	{
		return $this->getProperty('size');
	}

	/**
	 * @param string $state
	 * @see State
	 *
	 * @return Button
	 */
	public function setState($state)
	{
		return $this->setProperty('state', $state, State::class);
	}

	/**
	 * @return bool
	 */
	public function getState()
	{
		return $this->getProperty('state');
	}

	/**
	 * @param bool $flag
	 *
	 * @return $this
	 */
	public function setActive($flag = true)
	{
		if ($flag)
		{
			$this->setState(State::ACTIVE);
		}
		else
		{
			$this->setState(null);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isActive()
	{
		return $this->getState() === State::ACTIVE;
	}

	/**
	 * @param bool $flag
	 *
	 * @return $this
	 */
	public function setHovered($flag = true)
	{
		if ($flag)
		{
			$this->setState(State::HOVER);
		}
		else
		{
			$this->setState(null);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isHover()
	{
		return $this->getState() === State::HOVER;
	}

	/**
	 * @param bool $flag
	 *
	 * @return $this
	 */
	public function setDisabled($flag = true)
	{
		if ($flag)
		{
			$this->setState(State::DISABLED);
		}
		else
		{
			$this->setState(null);
		}

		return parent::setDisabled($flag);
	}

	/**
	 * @return bool
	 */
	public function isDisabled()
	{
		return $this->getState() === State::DISABLED;
	}

	/**
	 * @param bool $flag
	 *
	 * @return $this
	 */
	public function setWaiting($flag = true)
	{
		if ($flag)
		{
			$this->setState(State::WAITING);
			parent::setDisabled(true);
		}
		else
		{
			$this->setState(null);
			parent::setDisabled(false);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isWaiting()
	{
		return $this->getState() === State::WAITING;
	}

	/**
	 * @param bool $flag
	 *
	 * @return $this
	 */
	public function setClocking($flag = true)
	{
		if ($flag)
		{
			$this->setState(State::CLOCKING);
			parent::setDisabled(true);
		}
		else
		{
			$this->setState(null);
			parent::setDisabled(false);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isClocking()
	{
		return $this->getState() === State::CLOCKING;
	}

	/**
	 * @param bool $flag
	 * @return static
	 */
	public function setNoCaps($flag = true)
	{
		if ($flag === false)
		{
			$this->removeClass(Style::NO_CAPS);
		}
		else
		{
			$this->addClass(Style::NO_CAPS);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isNoCaps()
	{
		return $this->hasClass(Style::NO_CAPS);
	}

	/**
	 * @param bool $flag
	 * @return static
	 */
	public function setRound($flag = true)
	{
		if ($flag === false)
		{
			$this->removeClass(Style::ROUND);
		}
		else
		{
			$this->addClass(Style::ROUND);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRound()
	{
		return $this->hasClass(Style::ROUND);
	}

	/**
	 * @param bool $flag
	 * @return static
	 */
	public function setDropdown($flag = true)
	{
		if ($flag === false)
		{
			$this->removeClass(Style::DROPDOWN);
		}
		else
		{
			$this->addClass(Style::DROPDOWN);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDropdown()
	{
		return $this->hasClass(Style::DROPDOWN);
	}

	/**
	 * @param bool $flag
	 * @return static
	 */
	public function setCollapsed($flag = true)
	{
		if ($flag === false)
		{
			$this->removeClass(Style::COLLAPSED);
		}
		else
		{
			$this->addClass(Style::COLLAPSED);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isCollapsed()
	{
		return $this->hasClass(Style::COLLAPSED);
	}

	/**
	 * Sets a menu rendered by the main.popup extension. Only a payload that really sets a menu replaces
	 * a system one, as in the JS setter. The arrow is never touched.
	 *
	 * @param array $options
	 *
	 * @return $this
	 */
	public function setMenu($options)
	{
		if (self::hasMenuItems($options))
		{
			$this->removeSystemMenu();
		}

		$this->getAttributeCollection()->addJsonOption('menu', $options);

		return $this;
	}

	/**
	 * Sets a menu rendered by the ui.system.menu extension instead of the main.popup one.
	 * Options without a non-empty list of items are ignored and reported to the log for a developer,
	 * null removes the system menu only.
	 *
	 * @param array|null $options
	 *
	 * @return static
	 */
	public function setSystemMenu(?array $options): static
	{
		if ($options === null)
		{
			if (!$this->removeSystemMenu())
			{
				return $this;
			}

			// the arrow of a system menu is added by this setter, so it is removed by this setter too
			return $this->syncDropdownWithSystemMenu();
		}

		if (!self::hasMenuItems($options))
		{
			// the payload never reaches the client, so its own warning cannot fire: the reason is named here
			AddMessage2Log(
				'Button::setSystemMenu() expects a non-empty list of "items", the menu is not set',
				'ui',
			);

			return $this;
		}

		$attributes = $this->getAttributeCollection();
		if (self::hasMenuItems($attributes->getJsonOptions()['menu'] ?? null))
		{
			// the same reason: the dropped "menu" option cannot warn about itself on the client
			AddMessage2Log(
				'Button::setSystemMenu() drops the "menu" option: a button has one menu, the system one wins',
				'ui',
			);
		}

		$attributes
			->addJsonOption('systemMenu', $options)
			->removeJsonOption('menu')
		;

		// early load keeps the menu instant, listExtensions() is the fallback for a deferred render
		Extension::load('ui.system.menu');

		return $this->syncDropdownWithSystemMenu();
	}

	/**
	 * Removes the system menu options, the arrow is a caller's concern.
	 *
	 * @return bool whether there was a system menu to remove
	 */
	private function removeSystemMenu(): bool
	{
		if (!$this->hasSystemMenu())
		{
			return false;
		}

		$attributes = $this->getAttributeCollection();
		$attributes->removeJsonOption('systemMenu');
		if (empty($attributes->getJsonOptions()))
		{
			unset($attributes[ButtonAttributes::JSON_OPTIONS_DATA_ATTR]);
		}

		return true;
	}

	/**
	 * Keeps the arrow in sync with the system menu, an explicit "dropdown" option always wins.
	 */
	private function syncDropdownWithSystemMenu(): static
	{
		if (($this->getAttributeCollection()->getJsonOptions()['dropdown'] ?? null) === false)
		{
			return $this;
		}

		if (!$this->hasSystemMenu())
		{
			return $this->takeBackSystemMenuDropdown();
		}

		// an arrow that was already there belongs to the caller, so it is never taken back
		$this->dropdownBySystemMenu = $this->dropdownBySystemMenu || !$this->isDropdown();

		return $this->setDropdown();
	}

	/**
	 * Removes the arrow only when it was added by the system menu setter itself.
	 */
	private function takeBackSystemMenuDropdown(): static
	{
		if (!$this->dropdownBySystemMenu)
		{
			return $this;
		}

		$this->dropdownBySystemMenu = false;

		return $this->setDropdown(false);
	}

	protected function hasSystemMenu(): bool
	{
		return isset($this->getAttributeCollection()->getJsonOptions()['systemMenu']);
	}

	/**
	 * The single criterion of a payload that really sets a menu, both kinds of it: the client takes
	 * a menu only from a non-empty list of items, an associative array is dropped there.
	 */
	private static function hasMenuItems($options): bool
	{
		$items = is_array($options) ? ($options['items'] ?? null) : null;

		return is_array($items) && !empty($items) && array_is_list($items);
	}

	protected function listExtensions()
	{
		$extensions = parent::listExtensions();

		if ($this->hasSystemMenu())
		{
			$extensions[] = 'ui.system.menu';
		}

		return $extensions;
	}
}
