<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Entity\RoleType;
use Bitrix\Socialnetwork\V2\Internal\Integration;
use Bitrix\Socialnetwork\V2\Internal\Integration\Bitrix24\Service\Portal;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;

class LegacyProjectFeaturePolicy
{
	public function __construct(
		private readonly ProjectFeatureRepository $projectFeatureRepository,
		private readonly Integration\Lists\Service\Project $listsProjectService,
		private readonly Portal $portalService,
	)
	{
	}

	/**
	 * Feature states a new project starts with. It holds both the tools the project may never turn on and the
	 * ones that are merely off until someone turns them on, because create needs a state for each of them.
	 *
	 * @return array<string, bool>
	 */
	public function getDisabledFeatures(): array
	{
		return array_fill_keys(
			[
				...$this->getDisabledFeatureIds(),
				...$this->getInactiveOnCreateFeatureIds(),
			],
			false,
		);
	}

	/**
	 * Legacy tools a Project 2.0 may never turn on. What is listed here is also what a converted project is
	 * allowed to bring back, since conversion is the only way such a tool can have been active before.
	 *
	 * @return string[]
	 */
	public function getDisabledFeatureIds(): array
	{
		return [
			FeatureDictionary::Forum->value,
			FeatureDictionary::Wiki->value,
			FeatureDictionary::Photo->value,
			FeatureDictionary::LandingKnowledge->value,
		];
	}

	/**
	 * Tools switched off when the project is created. Their settings switches depend on portal availability.
	 *
	 * @return string[]
	 */
	public function getInactiveOnCreateFeatureIds(): array
	{
		return array_keys($this->getInactiveOnCreateFeatureAvailability());
	}

	/**
	 * The one place a tool merely off on create is declared: each is named together with the check that tells
	 * whether this portal offers it at all, taken from the very service {@see FeatureAvailabilityService} uses to
	 * keep the tool out of the project menu. Both lists above and below are read from here, so a tool cannot join
	 * them without saying how its availability is decided.
	 *
	 * @return array<string, callable(): bool>
	 */
	private function getInactiveOnCreateFeatureAvailability(): array
	{
		return [
			FeatureDictionary::GroupLists->value => fn (): bool => $this->listsProjectService->isAvailable()
				&& $this->isListsPortalEligible(),
		];
	}

	protected function isListsPortalEligible(): bool
	{
		return $this->portalService->isCreatedBeforeOrUnknown(
			new DateTime('2026-10-09 00:00:00', 'Y-m-d H:i:s'),
		);
	}

	/**
	 * Of the tools that are merely off on create, the ones this portal actually offers. The switch has to follow
	 * the very availability the project menu follows: a tool the portal does not register — module missing,
	 * product restriction — never reaches the menu, so offering its switch would promise what the project could
	 * never show. What stays off stays off: create still writes the state of every tool listed here.
	 *
	 * @return string[]
	 */
	public function getAvailableInactiveOnCreateFeatureIds(): array
	{
		$allowedFeatureIds = array_flip($this->projectFeatureRepository->getAllowedFeatureIds());

		$availableFeatureIds = [];
		foreach ($this->getInactiveOnCreateFeatureAvailability() as $featureId => $isIntegrationAvailable)
		{
			if (isset($allowedFeatureIds[$featureId]) && $isIntegrationAvailable())
			{
				$availableFeatureIds[] = $featureId;
			}
		}

		return $availableFeatureIds;
	}

	/**
	 * Permission overrides conversion puts on the legacy tools a Project 2.0 may never turn on. Lists are not
	 * among them any more: conversion leaves their permission to the project.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function getReadonlyPermissions(): array
	{
		return [
			FeatureDictionary::Forum->value => [
				'full' => RoleType::None->value,
				'newtopic' => RoleType::None->value,
				'answer' => RoleType::None->value,
			],
			FeatureDictionary::Wiki->value => [
				'write' => RoleType::None->value,
				'delete' => RoleType::None->value,
			],
			FeatureDictionary::Photo->value => [
				'write' => RoleType::None->value,
			],
		];
	}
}
