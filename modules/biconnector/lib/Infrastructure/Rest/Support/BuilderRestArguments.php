<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Support;

use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentNormalizer;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class BuilderRestArguments
{
	/**
	 * Authorization keys REST accepts in the body too: they are transport, not arguments of a method.
	 */
	private const TRANSPORT_KEYS = ['auth', 'access_token', 'auth_connector', 'sessid', 'state', 'aplogin', 'ap'];

	public static function prepare(string $uri, Request $bound, array $decoded): Result
	{
		foreach (self::TRANSPORT_KEYS as $key)
		{
			unset($decoded[$key]);
		}
		$contract = BuilderArgumentContract::forMethod($uri);
		foreach ($contract['properties'] ?? [] as $field => $schema)
		{
			// Request::create() treats every known root null as absence, including nullable carriers.
			if (array_key_exists($field, $decoded) && $decoded[$field] === null)
			{
				unset($decoded[$field]);
			}
		}
		$result = BuilderArgumentGuard::validate($uri, $decoded);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $result->setData(BuilderArgumentNormalizer::normalize($uri, $decoded));
	}

	public static function apply(Request $bound, array $normalized): void
	{
		$reflection = new \ReflectionObject($bound);
		foreach ($normalized as $field => $value)
		{
			if (!$reflection->hasProperty($field))
			{
				continue;
			}
			$property = $reflection->getProperty($field);
			if ($property->isPublic() && !$property->isStatic())
			{
				$bound->$field = $value;
			}
		}
	}
}
