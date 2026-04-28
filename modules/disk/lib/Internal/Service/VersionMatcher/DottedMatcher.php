<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\VersionMatcher;

class DottedMatcher implements MatcherInterface
{
	/**
	 * {@inheritDoc}
	 */
	public static function match(mixed $version, mixed $template): bool
	{
		if (!is_string($version) || !is_string($template))
		{
			return false;
		}

		$explodedVersion = explode('.', $version);
		$explodedTemplate = explode('.', $template);
		$explodedVersionLength = count($explodedVersion);

		if ($explodedVersionLength !== count($explodedTemplate))
		{
			return false;
		}

		for ($i = 0; $i < $explodedVersionLength; $i++)
		{
			$templateElement = $explodedTemplate[$i];

			if ($templateElement === '*')
			{
				continue;
			}

			$versionNumber = (int)$explodedVersion[$i];
			$templateNumber = (int)$templateElement;

			if ($versionNumber !== $templateNumber)
			{
				return false;
			}
		}

		return true;
	}
}
