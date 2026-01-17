<?php

namespace Bitrix\Bizproc\Internal\Trait;

trait SingletonTrait
{
	private static ?self $instance = null;

	public static function instance(): self
	{
		if (!self::$instance)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
	}
}
