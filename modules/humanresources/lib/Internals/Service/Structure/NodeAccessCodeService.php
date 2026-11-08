<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\AccessCodeType;
use InvalidArgumentException;

/**
 * Not final on purpose: the settings of the employee triggers reach this service through the internal
 * container, and a test of them answers the translation of a code with a double of this very class. A
 * final one cannot be doubled, and the alternatives are worse - reading the rows of a portal from a test
 * that touches no portal, or letting the integration layer ask the public facade instead, which the
 * architecture rules of the module forbid.
 */
class NodeAccessCodeService
{
	public function getNodeIdsByAccessCodes(array $accessCodes): array
	{
		[$nodeIds, , $internalAccessCodes] = $this->splitAccessCodes($accessCodes);

		$this->validateNodeIdsSize($accessCodes, $nodeIds, $internalAccessCodes);
		if (!empty($internalAccessCodes))
		{
			$oldDepartmentIds = InternalContainer::getNodeAccessCodeRepository()->getNodeIdsByAccessCodes($internalAccessCodes);

			return array_merge($nodeIds, $oldDepartmentIds);
		}

		return $nodeIds;
	}

	/**
	 * The same reading, keeping which code each node came from. A caller that has to tell an access code
	 * resolving into nothing from one resolving into a node cannot do it over a flat list of ids, and
	 * asking a code at a time costs a query per code.
	 *
	 * @param array<string> $accessCodes
	 *
	 * @return array<string, list<int>> Codes as they were asked for; a code naming nothing is absent.
	 */
	public function getNodeIdsGroupedByAccessCode(array $accessCodes): array
	{
		[$nodeIds, $nodeIdsByCode, $internalAccessCodes] = $this->splitAccessCodes($accessCodes);

		$this->validateNodeIdsSize($accessCodes, $nodeIds, $internalAccessCodes);

		$grouped = [];
		foreach ($nodeIdsByCode as $accessCode => $nodeId)
		{
			$grouped[$accessCode] = [$nodeId];
		}

		if (empty($internalAccessCodes))
		{
			return $grouped;
		}

		$fromStructure = InternalContainer::getNodeAccessCodeRepository()
			->findNodeIdsGroupedByAccessCode($internalAccessCodes)
		;

		return $grouped + $fromStructure;
	}

	/**
	 * Codes the prefix alone resolves - as a flat list in the order they were asked, and by code - together
	 * with the codes of the old structure the structure has to be asked about. The flat list keeps repeats
	 * and the map does not, so the count check stays the check it was.
	 *
	 * @return array{0: list<int>, 1: array<string, int>, 2: list<string>}
	 */
	private function splitAccessCodes(array $accessCodes): array
	{
		$allPrefixes = $this->getSupportedPrefixes();
		// Sort by length (from longest to shortest) to avoid conflicts
		usort($allPrefixes, fn($a, $b) => strlen($b) <=> strlen($a));

		$nodeIds = [];
		$nodeIdsByCode = [];
		$internalAccessCodes = [];
		foreach ($accessCodes as $accessCode)
		{
			foreach ($allPrefixes as $prefix)
			{
				if (str_starts_with($accessCode, $prefix))
				{
					if (in_array($prefix, AccessCodeType::getIntranetDepartmentTypesPrefixes()))
					{
						$nodeId = substr($accessCode, strlen($prefix));
						if (is_numeric($nodeId))
						{
							$internalAccessCodes[] = $accessCode;

							break;
						}
					}

					$nodeId = substr($accessCode, strlen($prefix));
					if (is_numeric($nodeId))
					{
						$nodeIds[] = (int)$nodeId;
						$nodeIdsByCode[$accessCode] = (int)$nodeId;

						break;
					}
				}
			}
		}

		return [$nodeIds, $nodeIdsByCode, $internalAccessCodes];
	}

	private function getSupportedPrefixes(): array
	{
		return array_merge(
			AccessCodeType::getAllStructurePrefixes(),
			AccessCodeType::getIntranetDepartmentTypesPrefixes(),
		);
	}

	private function validateNodeIdsSize(array $accessCodes, array $filteredIds, array $internalAccessCodes): void
	{
		$expectedSize = count($accessCodes) - count($internalAccessCodes);
		if (count($filteredIds) === $expectedSize)
		{
			return;
		}

		throw new InvalidArgumentException('Invalid access codes detected in the array');
	}
}