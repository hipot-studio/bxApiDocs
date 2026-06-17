<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Main\UI\Uploader;

use Bitrix\Main;

class Log implements \ArrayAccess
{
	/*
	 * @var \CBXVirtualFileFileSystem $file
	 */
	protected $file = null;
	var $data = array();

	/**
	 * Log constructor.
	 * @param string $path Path to log file.
	 * @return void
	 */
	function __construct($path)
	{
		try
		{
			$this->file = \CBXVirtualIo::GetInstance()->GetFile($path);

			if ($this->file->IsExists())
			{
				$data = unserialize($this->file->GetContents(), ["allowed_classes" => false]);
				foreach($data as $key => $val)
				{
					if (array_key_exists($key , $this->data) && is_array($this->data[$key]) && is_array($val))
						$this->data[$key] = array_merge($this->data[$key], $val);
					else
						$this->data[$key] = $val;
				}
			}
		}
		catch (\Throwable $e)
		{
			throw new Main\SystemException("Temporary file has wrong structure.", "BXU351.01");
		}
	}

	/**
	 * Saves log.
	 * @param string $key Key of log array.
	 * @param mixed $value value of log array.
	 * @return $this
	 */
	public function setLog($key, $value)
	{
		if (array_key_exists($key, $this->data) && is_array($this->data) && is_array($value))
			$this->data[$key] = array_merge($this->data[$key], $value);
		else
			$this->data[$key] = $value;
		$this->save();

		return $this;
	}

	/**
	 * @param $key
	 * @return mixed
	 */
	public function getValue($key)
	{
		return $this->data[$key];
	}

	/**
	 *
	 */
	public function save()
	{
		try
		{
			$this->file->PutContents(serialize($this->data));
		}
		catch (\Throwable $e)
		{
			throw new Main\SystemException("Temporary file was not saved.", "BXU351.02");
		}
	}

	/**
	 * @return array
	 */
	public function getLog()
	{
		return $this->data;
	}

	/**
	 *
	 */
	public function unlink()
	{
		try
		{
			if ($this->file instanceof \CBXVirtualFileFileSystem && $this->file->IsExists())
				$this->file->unlink();
		}
		catch (\Throwable $e)
		{
			throw new Main\SystemException("Temporary file was not deleted.", "BXU351.03");
		}
	}

	/**
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists($offset): bool
	{
		return array_key_exists($offset, $this->data);
	}

	/**
	 * @param mixed $offset
	 * @return mixed|null
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet($offset)
	{
		if (array_key_exists($offset, $this->data))
			return $this->data[$offset];
		return null;
	}

	/**
	 * @param mixed $offset
	 * @param mixed $value
	 */
	public function offsetSet($offset, $value): void
	{
		$this->setLog($offset, $value);
	}

	/**
	 * @param mixed $offset
	 */
	public function offsetUnset($offset): void
	{
		if (array_key_exists($offset, $this->data))
		{
			unset($this->data[$offset]);
			$this->save();
		}
	}
}
