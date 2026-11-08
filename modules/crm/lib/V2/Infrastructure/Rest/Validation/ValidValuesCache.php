<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CacheKeyProviderInterface;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\Context;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ValidValuesProviderInterface;
use Bitrix\Main\HttpRequest;

final class ValidValuesCache
{
	private static ?array $values = null;

	public static function runForRequest(?HttpRequest $request, callable $callback): mixed
	{
		$previousValues = self::$values;
		self::$values = $request === null ? null : [];

		try
		{
			return $callback();
		}
		finally
		{
			self::$values = $previousValues;
		}
	}

	public static function get(string|ValidValuesProviderInterface $provider, Context $context): array
	{
		$providerClass = is_string($provider) ? $provider : $provider::class;

		if (self::$values === null || !is_subclass_of($providerClass, CacheKeyProviderInterface::class))
		{
			return $providerClass::getValidValues($context);
		}

		$keyParts = $providerClass::getCacheKey($context);
		if ($keyParts === null || !array_is_list($keyParts))
		{
			return $providerClass::getValidValues($context);
		}

		foreach ($keyParts as $part)
		{
			if (!is_scalar($part))
			{
				return $providerClass::getValidValues($context);
			}
		}

		$key = serialize([$providerClass, $context->getUserId(), $context->isShowValues(), $keyParts]);
		if (!array_key_exists($key, self::$values))
		{
			self::$values[$key] = $providerClass::getValidValues($context);
		}

		return self::$values[$key];
	}
}
