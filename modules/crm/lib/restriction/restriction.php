<?php

namespace Bitrix\Crm\Restriction;

use Bitrix\Main\Config\Option;

abstract class Restriction
{
	/** @var string  */
	protected $name = '';

	/** @var bool  */
	protected $isPersistent = false;

	abstract public function externalize();
	abstract public function internalize(array $params);
	abstract public function preparePopupScript();
	abstract public function getHtml();

	public function __construct($name)
	{
		$this->setName($name);
	}

	public function getName()
	{
		return $this->name;
	}

	public function setName($name)
	{
		$this->name = (string)$name;
	}

	/**
	* @deprecated No longer used by internal code and not recommended. Please use isPersistent.
	* @return bool
	*/
	public function isPersitent()
	{
		return $this->isPersistent;
	}

	public function isPersistent()
	{
		return $this->isPersistent;
	}

	public function save()
	{
		$this->isPersistent = false;

		if ($this->name !== '')
		{
			Option::set(
				'crm',
				$this->name,
				serialize($this->externalize()),
			);

			$this->isPersistent = true;
		}

		return $this->isPersistent;
	}

	public function load()
	{
		$this->isPersistent = false;

		if ($this->name !== '')
		{
			$saved = Option::get('crm', $this->name, '', '');
			$params = str_starts_with($saved, 'a:')
				? unserialize($saved, ['allowed_classes' => false])
				: null
			;
			if (is_array($params) && !empty($params))
			{
				$this->internalize($params);
				$this->isPersistent = true;
			}
		}

		return $this->isPersistent;
	}

	public function reset()
	{
		$this->isPersistent = false;
		if ($this->name !== '')
		{
			Option::delete('crm', ['name' => $this->name]);
		}
	}
}
