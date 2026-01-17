<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Activity;

class ActivityLinkService
{
	private const PORT_DELIMITER = ':';
	private const AUX_PORT_PREFIX = 'a';

	public static function buildAuxPortName(string $activityName, int $portIndex = 0): string
	{
		return $activityName . self::PORT_DELIMITER . self::AUX_PORT_PREFIX . $portIndex;
	}

	public static function isAuxSourceOf(string $link, string $activityName, ?int $index = null): bool
	{
		if ($index !== null)
		{
			return $link === self::buildAuxPortName($activityName, $index);
		}

		return str_starts_with($link, $activityName . self::PORT_DELIMITER . self::AUX_PORT_PREFIX);
	}

	/**
	 * @return array{string, string}
	 */
	public static function parseLink(string $link): array
	{
		$parts = explode(self::PORT_DELIMITER, $link, 2);

		return [$parts[0] ?? '', $parts[1] ?? ''];
	}

	public static function getAuxNodes(array $template, string $sourceActivityName): array
	{
		$sourceLink = static::buildAuxPortName($sourceActivityName);
		$nodes = [];

		$links = $template['TEMPLATE'][0]['Properties']['Links'] ?? [];
		foreach ($links as $link)
		{
			if (is_array($link) && isset($link[0], $link[1]) && $link[0] === $sourceLink)
			{
				$targetNodeLink = $link[1];
				[$activityName] = static::parseLink($targetNodeLink);
				if ($activityName)
				{
					$activity = \CBPWorkflowTemplateLoader::FindActivityByName(
						$template['TEMPLATE'],
						$activityName
					);
					if ($activity)
					{
						$nodes[] = $activity;
					}
				}
			}
		}

		return $nodes;
	}
}
