<?php
namespace Bitrix\Main\Data;

class CacheEngineNone extends CacheEngine
{
	public function getConnectionName(): string
	{
		return '';
	}

	public static function getConnectionClass(): string
	{
		return CacheEngineNone::class;
	}

	protected function configure($options = []): array
	{
		return [];
	}

	protected function connect($config)
	{

	}

	public function isAvailable()
	{
		return true;
	}

	public function clean($baseDir, $initDir = false, $filename = false)
	{
		return true;
	}

	public function read(&$vars, $baseDir, $initDir, $filename, $ttl)
	{
		$vars = false;
		return false;
	}

	public function write($vars, $baseDir, $initDir, $filename, $ttl)
	{
	}

	public function set($key, $ttl, $value)
	{
		return false;
	}

	public function get($key)
	{
		return false;
	}

	public function del($key)
	{
	}

	public function setNotExists($key, $ttl, $value)
	{
	}

	public function checkInSet($key, $value): bool
	{
		return false;
	}

	public function addToSet($key, $value)
	{
	}

	public function getSet($key): array
	{
		return [];
	}

	public function delFromSet($key, $member)
	{
	}

	public function deleteBySet($key, $prefix = '')
	{
	}
}