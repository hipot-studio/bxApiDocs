<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Control\Operation;

use Bitrix\Socialnetwork\Control\GroupResult;
use Bitrix\Socialnetwork\Control\Mapper\Mapper;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;

abstract class AbstractOperation
{
	private ?Mapper $mapper = null;
	private ?GroupRegistry $registry = null;

	abstract public function run(): GroupResult;

	protected function getMapper(): Mapper
	{
		return $this->mapper ??= $this->createMapper();
	}

	protected function createMapper(): Mapper
	{
		return new Mapper();
	}

	protected function getRegistry(): GroupRegistry
	{
		return $this->registry ??= $this->createRegistry();
	}

	protected function createRegistry(): GroupRegistry
	{
		return GroupRegistry::getInstance();
	}
}
