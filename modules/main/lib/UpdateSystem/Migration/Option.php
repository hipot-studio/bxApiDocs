<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

class Option
{
	public function __construct(
		private readonly Context $context,
	)
	{
	}

	public function get(string $moduleId, string $name, string $default = ''): mixed
	{
		return \Bitrix\Main\Config\Option::get($moduleId, $name, $default);
	}

	public function set(string $moduleId, string $name, string $value): void
	{
		if ($this->context->getDatabaseUpdateMode()->canUpdateDatabase() && $this->context->moduleTablesExist())
		{
			\Bitrix\Main\Config\Option::set($moduleId, $name, $value);
		}
	}

	public function delete(string $moduleId, string $name): void
	{
		if ($this->context->getDatabaseUpdateMode()->canUpdateDatabase())
		{
			\Bitrix\Main\Config\Option::delete($moduleId, ['name' => $name]);
		}
	}
}
