<?php

namespace Bitrix\Main\UpdateSystem;

use Bitrix\Main\UpdateSystem\Migration\Agent;
use Bitrix\Main\UpdateSystem\Migration\Config;
use Bitrix\Main\UpdateSystem\Migration\Context;
use Bitrix\Main\UpdateSystem\Migration\ConfigFactory;
use Bitrix\Main\UpdateSystem\Migration\DatabaseUpdateMode;
use Bitrix\Main\UpdateSystem\Migration\Event;
use Bitrix\Main\UpdateSystem\Migration\Files;
use Bitrix\Main\UpdateSystem\Migration\Module;
use Bitrix\Main\UpdateSystem\Migration\Option;
use Bitrix\Main\UpdateSystem\Migration\Stepper;
use Bitrix\Main\UpdateSystem\Migration\Table;

class Migration
{
	private ?Context $context = null;

	/** @var Table[]  */
	private array $tables = [];
	private ?Agent $agent = null;
	private ?Stepper $stepper = null;
	private ?Event $event = null;
	private ?Option $option = null;
	private ?Module $module = null;
	private ?Files $files = null;

	private static array $instances = [];

	public static function getInstance(): self
	{
		$config = ConfigFactory::getConfig();

		$instanceKey = $config->getUpdaterFilename();
		if ($config->getDatabaseUpdateMode() === DatabaseUpdateMode::DdlOnly)
		{
			$instanceKey .= '_dll'; // create separated instance for ddl only mode, because it can be used on the same hit with main instance
		}
		if (!isset(self::$instances[$instanceKey]))
		{
			self::$instances[$instanceKey] = new self($config);
		}

		return self::$instances[$instanceKey];
	}

	private function __construct(private readonly Config $config)
	{
	}

	public function context(): Context
	{
		if (!$this->context)
		{
			$this->context = new Context($this->config);
		}

		return $this->context;
	}

	public function table(string $tableName): Table
	{
		if (!isset($this->tables[$tableName]))
		{
			$this->tables[$tableName] = new Table($tableName, $this->context());
		}

		return $this->tables[$tableName];
	}

	public function agent(): Agent
	{
		if (!$this->agent)
		{
			$this->agent = new Agent($this->context());
		}

		return $this->agent;
	}

	public function stepper(): Stepper
	{
		if (!$this->stepper)
		{
			$this->stepper = new Stepper($this->context());
		}

		return $this->stepper;
	}

	public function event(): Event
	{
		if (!$this->event)
		{
			$this->event = new Event($this->context());
		}

		return $this->event;
	}

	public function option(): Option
	{
		if (!$this->option)
		{
			$this->option = new Option($this->context());
		}

		return $this->option;
	}

	public function module(): Module
	{
		if (!$this->module)
		{
			$this->module = new Module($this->context());
		}

		return $this->module;
	}

	public function files(): Files
	{
		if (!$this->files)
		{
			$this->files = new Files($this->context());
		}

		return $this->files;
	}
}
