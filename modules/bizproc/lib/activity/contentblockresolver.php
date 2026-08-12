<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Dto\ContentBlock;
use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;
use Bitrix\Bizproc\Activity\Dto\ContentBlockScope;
use Bitrix\Bizproc\Public\Activity\Interface\ActivityContentBlockProviderInterface;
use Bitrix\Bizproc\Public\Activity\Interface\ContentBlockScopeConsumerInterface;
use Bitrix\Bizproc\Public\Activity\Interface\ContentBlockScopeProducerInterface;
use Bitrix\Main\Application;

/**
 * Resolves the canvas content block declared by an activity through ActivityContentBlockProviderInterface.
 *
 * The editor stays agnostic of concrete node types: it only renders whatever this resolver
 * produces. Activities that do not implement the interface simply have no content block.
 */
final class ContentBlockResolver
{
	public static function resolve(
		string $activityType,
		array $properties,
		?ContentBlockContext $context = null,
	): ?ContentBlock
	{
		return self::resolveWithClass(self::resolveClassName($activityType), $properties, $context);
	}

	/**
	 * Resolves the content block for an already-resolved runtime class name. Lets callers that loop
	 * over a whole template resolve each class once and reuse it (no repeated includeActivityFile).
	 */
	private static function resolveWithClass(
		?string $className,
		array $properties,
		?ContentBlockContext $context,
	): ?ContentBlock
	{
		if ($className === null)
		{
			return null;
		}

		if (!isset(class_implements($className, false)[ActivityContentBlockProviderInterface::class]))
		{
			return null;
		}

		try
		{
			return $className::getContentBlock($properties, $context);
		}
		catch (\Throwable $e)
		{
			// Fail-closed: one activity must not break the whole diagram render. Log for diagnostics.
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	/**
	 * Resolves content blocks for a whole template in one pass: builds the shared scope from every
	 * producer activity, then resolves each activity against it. Keyed by activity Name.
	 *
	 * @param array<int, array{Type?: string, Name?: string, Properties?: array}> $activities
	 * @return array<string, ?ContentBlock>
	 */
	public static function resolveForTemplate(array $activities): array
	{
		$classNames = [];
		foreach ($activities as $index => $activity)
		{
			$classNames[$index] = self::resolveClassName((string)($activity['Type'] ?? ''));
		}

		$context = new ContentBlockContext(scope: self::buildScope($activities, $classNames));

		$result = [];
		foreach ($activities as $index => $activity)
		{
			$name = (string)($activity['Name'] ?? '');
			if ($name === '')
			{
				continue;
			}

			$result[$name] = self::resolveWithClass(
				$classNames[$index],
				is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [],
				$context,
			);
		}

		return $result;
	}

	/**
	 * Assembles the resolution scope from every producer activity in the given list.
	 *
	 * @param array<int, array{Type?: string, Properties?: array}> $activities
	 * @param array<int, ?string>|null $classNames Optional precomputed runtime class names keyed by the
	 *        same index as $activities, so a caller that already resolved them avoids a second pass.
	 */
	public static function buildScope(array $activities, ?array $classNames = null): ContentBlockScope
	{
		$scope = new ContentBlockScope();

		foreach ($activities as $index => $activity)
		{
			$className = $classNames[$index] ?? self::resolveClassName((string)($activity['Type'] ?? ''));
			if ($className === null)
			{
				continue;
			}

			if (!isset(class_implements($className, false)[ContentBlockScopeProducerInterface::class]))
			{
				continue;
			}

			try
			{
				$descriptor = $className::getScopeContribution();
				$properties = is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [];
				$scope->declare(
					(string)($descriptor['namespace'] ?? ''),
					(string)($properties[$descriptor['keyProperty'] ?? ''] ?? ''),
					(string)($properties[$descriptor['labelProperty'] ?? ''] ?? ''),
				);
			}
			catch (\Throwable $e)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($e);
			}
		}

		return $scope;
	}

	/**
	 * Declarative scope contribution of a producer activity type, or null if the type is not a
	 * producer. Surfaced to the editor client (via the catalog) so it can build the scope reactively.
	 */
	public static function getScopeContribution(string $activityType): ?array
	{
		$className = self::resolveClassName($activityType);
		if (
			$className === null
			|| !isset(class_implements($className, false)[ContentBlockScopeProducerInterface::class])
		)
		{
			return null;
		}

		try
		{
			return $className::getScopeContribution();
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	/**
	 * Declarative scope consumption of a consumer activity type, or null if the type does not consume
	 * the scope. Surfaced to the editor client so it can resolve the cross-node label reactively.
	 */
	public static function getScopeConsumption(string $activityType): ?array
	{
		$className = self::resolveClassName($activityType);
		if (
			$className === null
			|| !isset(class_implements($className, false)[ContentBlockScopeConsumerInterface::class])
		)
		{
			return null;
		}

		try
		{
			return $className::getScopeConsumption();
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	/**
	 * Loads the activity file and returns its runtime class name, or null if unavailable.
	 * Activity code validation happens inside includeActivityFile(): invalid codes are rejected there.
	 */
	private static function resolveClassName(string $activityType): ?string
	{
		$runtime = \CBPRuntime::getRuntime();
		if (!$runtime->includeActivityFile($activityType))
		{
			return null;
		}

		$className = str_starts_with($activityType, 'CBP') ? $activityType : 'CBP' . $activityType;

		return class_exists($className) ? $className : null;
	}
}
