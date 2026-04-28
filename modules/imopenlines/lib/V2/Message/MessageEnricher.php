<?php
namespace Bitrix\ImOpenLines\V2\Message;

use Bitrix\Im\V2\Message;
use Bitrix\ImOpenLines\V2\Message\Modifier\MessageModifierInterface;

class MessageEnricher
{
	private static ?self $instance = null;
	private array $modifierObjects = [];

	private const MODIFIER_CLASSES = [
		\Bitrix\ImOpenLines\V2\Message\Modifier\SilentModeModifier::class,
	];

	private function __construct()
	{
		foreach (self::MODIFIER_CLASSES as $modifierClass)
		{
			if (class_exists($modifierClass))
			{
				$this->modifierObjects[] = new $modifierClass();
			}
		}
	}

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function enrich(Message $message): void
	{
		foreach ($this->modifierObjects as $modifier)
		{
			/** @var MessageModifierInterface $modifier */
			if ($modifier->supports($message))
			{
				$modifier->modify($message);
			}
		}
	}

	private function __clone() {}

	public function __wakeup()
	{
		throw new \Bitrix\Main\NotImplementedException();
	}
}
