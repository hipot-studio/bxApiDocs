<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Config\Feature;

use Bitrix\HumanResources\Public\Service\NodeService;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Loader;

final class HumanResourcesStructureApi extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}

	public function getRequirements(): array
	{
		return [
			static fn (): bool => self::isProvidedByHumanResources(),
		];
	}

	public static function isProvidedByHumanResources(): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		return method_exists(NodeService::class, 'getNodeIdsGroupedByAccessCode')
			&& self::acceptsViewer('findAll')
			&& self::acceptsViewer('findAllByIds')
		;
	}

	private static function acceptsViewer(string $method): bool
	{
		foreach ((new \ReflectionMethod(NodeService::class, $method))->getParameters() as $parameter)
		{
			if ($parameter->getName() === 'viewerUserId')
			{
				return true;
			}
		}

		return false;
	}
}
