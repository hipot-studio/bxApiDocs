<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Entity;

use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ProcessingParamInterface;
use Bitrix\Main\Messenger\Internals\Exception\Broker\SendFailedException;
use Bitrix\Main\Messenger\Internals\MessageBus;

abstract class AbstractMessage implements MessageInterface
{
	/**
	 * @param string $queueId
	 * @param ProcessingParamInterface[] $params
	 *
	 * @throws ConfigurationException
	 * @throws SendFailedException
	 */
	public function send(string $queueId, array $params = []): void
	{
		$bus = ServiceLocator::getInstance()->get(MessageBus::class);

		$bus->send($this, $queueId, $params);
	}

	/**
	 * @inheritDoc
	 */
	public static function createFromData(array $data): MessageInterface
	{
		$class = new \ReflectionClass(
			get_called_class()
		);

		$construct = $class->getConstructor();
		if ($construct)
		{
			$args = [];
			foreach ($construct->getParameters() as $parameter)
			{
				$args[$parameter->getName()] = $data[$parameter->getName()] ?? null;
			}

			return $class->newInstanceArgs($args);
		}

		$instance = $class->newInstanceWithoutConstructor();
		foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property)
		{
			if (array_key_exists($property->getName(), $data))
			{
				$property->setValue($instance, $data[$property->getName()]);
			}
		}

		return $instance;
	}

	/**
	 * @inheritDoc
	 */
	public function jsonSerialize(): mixed
	{
		$result = [];

		$class = new \ReflectionClass($this);
		foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property)
		{
			$result[$property->getName()] = $property->getValue($this);
		}

		return $result;
	}
}
