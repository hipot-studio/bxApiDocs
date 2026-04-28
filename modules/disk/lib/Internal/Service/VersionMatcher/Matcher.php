<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\VersionMatcher;

use Bitrix\Disk\Internal\Enum\VersionTypes;
use RuntimeException;

readonly class Matcher
{
	/**
	 * @param array|null $config
	 */
	public function __construct(
		protected ?array $config,
	)
	{
	}

	/**
	 * @param VersionTypes $type
	 * @param mixed $version
	 * @param array $templates
	 * @return mixed
	 */
	public function matchMultiple(VersionTypes $type, mixed $version, array $templates): mixed
	{
		$matcher = $this->config[$type->value] ?? null;

		if (!is_a($matcher, MatcherInterface::class, true))
		{
			throw new RuntimeException("Version type \"$type->value\" not supported");
		}

		foreach ($templates as $template)
		{
			$isMatched = $matcher::match($version, $template);

			if ($isMatched)
			{
				return $template;
			}
		}

		return null;
	}
}
