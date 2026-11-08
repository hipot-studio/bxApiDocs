<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\PaySystem;

use Bitrix\Sale\BusinessValue;

final class CheckedBusinessValueContext
{
	public function run(string $consumerKey, callable $descriptionFactory, callable $action): mixed
	{
		$reflection = new \ReflectionClass(BusinessValue::class);
		$properties = [];
		$previous = [];
		foreach (['consumers', 'commonConsumer'] as $name)
		{
			if (!$reflection->hasProperty($name))
			{
				throw new \RuntimeException('Unsupported payment metadata context.');
			}
			$property = $reflection->getProperty($name);
			if (!$property->isStatic() || !$property->isPrivate() || !is_array($property->getValue()))
			{
				throw new \RuntimeException('Unsupported payment metadata context.');
			}
			$properties[$name] = $property;
			$previous[$name] = $property->getValue();
		}

		try
		{
			$common = ['NAME' => '', 'SORT' => 0, 'CODES' => []];
			$properties['commonConsumer']->setValue(null, $common);
			$properties['consumers']->setValue(null, ['' => $common]);
			$description = $descriptionFactory();
			if (!is_array($description))
			{
				throw new \RuntimeException('Invalid payment metadata context.');
			}
			$properties['consumers']->setValue(null, ['' => $common, $consumerKey => $description]);

			return $action();
		}
		finally
		{
			$properties['consumers']->setValue(null, $previous['consumers']);
			$properties['commonConsumer']->setValue(null, $previous['commonConsumer']);
		}
	}
}
