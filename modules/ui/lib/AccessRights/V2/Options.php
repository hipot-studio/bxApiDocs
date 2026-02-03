<?php

namespace Bitrix\UI\AccessRights\V2;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\UI\AccessRights\V2\Options\RightSection;
use Bitrix\UI\AccessRights\V2\Options\AdditionalMemberOptions;
use Bitrix\UI\AccessRights\V2\Options\UserGroup;
use JsonSerializable;

class Options implements JsonSerializable, Arrayable
{
	protected ?string $moduleId = null;
	protected ?string $actionSave = null;
	protected ?string $mode = null;

	protected ?string $bodyType = null;

	protected ?string $userSortConfigName = null;
	protected ?array $sortConfigForAllUserGroups = null;

	protected array $additionalSaveParams = [];

	protected array $analytics = [];

	protected ?bool $isSaveOnlyChangedRights = null;

	protected ?bool $isSaveAccessRightsList = null;

	protected ?int $maxVisibleUserGroups = null;

	protected ?string $searchContainerSelector = null;

	protected AdditionalMemberOptions $additionalMembersParams;

	/** @var UserGroup[] */
	protected array $userGroups = [];

	/** @var RightSection[] */
	protected array $accessRights = [];

	public function __construct(
		protected string $component,
		protected string $containerId,
	)
	{
		$this->additionalMembersParams = (new AdditionalMemberOptions());
	}

	public function getComponent(): string
	{
		return $this->component;
	}

	public function setComponent(string $component): static
	{
		$this->component = $component;

		return $this;
	}

	public function getContainerId(): string
	{
		return $this->containerId;
	}

	public function setContainerId(string $containerId): static
	{
		$this->containerId = $containerId;

		return $this;
	}

	public function getModuleId(): ?string
	{
		return $this->moduleId;
	}

	public function setModuleId(?string $moduleId): static
	{
		$this->moduleId = $moduleId;

		return $this;
	}

	public function getActionSave(): ?string
	{
		return $this->actionSave;
	}

	public function setActionSave(?string $actionSave): static
	{
		$this->actionSave = $actionSave;

		return $this;
	}

	public function getMode(): ?string
	{
		return $this->mode;
	}

	public function setMode(?string $mode): static
	{
		$this->mode = $mode;

		return $this;
	}

	public function getBodyType(): ?string
	{
		return $this->bodyType;
	}

	public function setBodyType(?string $bodyType): static
	{
		$this->bodyType = $bodyType;

		return $this;
	}

	public function getUserSortConfigName(): ?string
	{
		return $this->userSortConfigName;
	}

	public function setUserSortConfigName(?string $userSortConfigName): static
	{
		$this->userSortConfigName = $userSortConfigName;

		return $this;
	}

	public function getSortConfigForAllUserGroups(): ?array
	{
		return $this->sortConfigForAllUserGroups;
	}

	public function setSortConfigForAllUserGroups(?array $sortConfigForAllUserGroups): static
	{
		$this->sortConfigForAllUserGroups = $sortConfigForAllUserGroups;

		return $this;
	}

	public function getAdditionalSaveParams(): array
	{
		return $this->additionalSaveParams;
	}

	public function setAdditionalSaveParams(array $additionalSaveParams): static
	{
		$this->additionalSaveParams = $additionalSaveParams;

		return $this;
	}

	public function getAnalytics(): array
	{
		return $this->analytics;
	}

	public function setAnalytics(array $analytics): static
	{
		$this->analytics = $analytics;

		return $this;
	}

	public function isSaveOnlyChangedRights(): ?bool
	{
		return $this->isSaveOnlyChangedRights;
	}

	public function setIsSaveOnlyChangedRights(?bool $isSaveOnlyChangedRights): static
	{
		$this->isSaveOnlyChangedRights = $isSaveOnlyChangedRights;

		return $this;
	}

	public function isSaveAccessRightsList(): ?bool
	{
		return $this->isSaveAccessRightsList;
	}

	public function setIsSaveAccessRightsList(?bool $isSaveAccessRightsList): static
	{
		$this->isSaveAccessRightsList = $isSaveAccessRightsList;

		return $this;
	}

	public function getMaxVisibleUserGroups(): ?int
	{
		return $this->maxVisibleUserGroups;
	}

	public function setMaxVisibleUserGroups(?int $maxVisibleUserGroups): static
	{
		$this->maxVisibleUserGroups = $maxVisibleUserGroups;

		return $this;
	}

	public function getSearchContainerSelector(): ?string
	{
		return $this->searchContainerSelector;
	}

	public function setSearchContainerSelector(?string $searchContainerSelector): static
	{
		$this->searchContainerSelector = $searchContainerSelector;

		return $this;
	}

	public function getAdditionalMembersParams(): AdditionalMemberOptions
	{
		return $this->additionalMembersParams;
	}

	/**
	 * @param callable(AdditionalMemberOptions $params): void $configurator
	 * @return $this
	 */
	public function configureAdditionalMembersParams(callable $configurator): static
	{
		$configurator($this->additionalMembersParams);

		return $this;
	}

	public function setAdditionalMembersParams(AdditionalMemberOptions $additionalMembersParams): static
	{
		$this->additionalMembersParams = $additionalMembersParams;

		return $this;
	}

	public function getUserGroups(): array
	{
		return $this->userGroups;
	}

	public function setUserGroups(array $userGroups): static
	{
		$this->userGroups = $userGroups;

		return $this;
	}

	public function addUserGroup(UserGroup $userGroup): static
	{
		$this->userGroups[] = $userGroup;

		return $this;
	}

	public function getAccessRights(): array
	{
		return $this->accessRights;
	}

	public function setAccessRights(array $accessRights): static
	{
		$this->accessRights = $accessRights;

		return $this;
	}

	public function addAccessRight(RightSection $accessRight): static
	{
		$this->accessRights[] = $accessRight;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'component' => $this->getComponent(),
			'moduleId' => $this->getModuleId(),
			'actionSave' => $this->getActionSave(),
			'mode' => $this->getMode(),
			'bodyType' => $this->getBodyType(),
			'userSortConfigName' => $this->getUserSortConfigName(),
			'sortConfigForAllUserGroups' => $this->getSortConfigForAllUserGroups(),
			'additionalSaveParams' => $this->getAdditionalSaveParams(),
			'analytics' => $this->getAnalytics(),
			'isSaveOnlyChangedRights' => $this->isSaveOnlyChangedRights(),
			'isSaveAccessRightsList' => $this->isSaveAccessRightsList(),
			'maxVisibleUserGroups' => $this->getMaxVisibleUserGroups(),
			'searchContainerSelector' => $this->getSearchContainerSelector(),
			'additionalMembersParams' => $this->getAdditionalMembersParams()->toArray(),
			'renderToContainerId' => $this->getContainerId(),
			'userGroups' => array_map(static fn (UserGroup $userGroup) => $userGroup->toArray(), $this->userGroups),
			'accessRights' => array_map(static fn (RightSection $section) => $section->toArray(), $this->accessRights),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function tryFromArray(array $data): ?self
	{
		if (
			!isset($data['component'])
			|| !is_string($data['component'])
			|| !isset($data['renderToContainerId'])
			|| !is_string($data['renderToContainerId'])
		)
		{
			return null;
		}

		$options = new self(
			$data['component'],
			$data['renderToContainerId'],
		);

		if (isset($data['moduleId']))
		{
			$options->setModuleId((string)$data['moduleId']);
		}

		if (isset($data['actionSave']))
		{
			$options->setActionSave((string)$data['actionSave']);
		}

		if (isset($data['mode']))
		{
			$options->setMode((string)$data['mode']);
		}

		if (isset($data['bodyType']))
		{
			$options->setBodyType((string)$data['bodyType']);
		}

		if (isset($data['userSortConfigName']))
		{
			$options->setUserSortConfigName((string)$data['userSortConfigName']);
		}

		if (isset($data['sortConfigForAllUserGroups']) && is_array($data['sortConfigForAllUserGroups']))
		{
			$options->setSortConfigForAllUserGroups($data['sortConfigForAllUserGroups']);
		}

		if (isset($data['additionalSaveParams']) && is_array($data['additionalSaveParams']))
		{
			$options->setAdditionalSaveParams($data['additionalSaveParams']);
		}

		if (isset($data['isSaveOnlyChangedRights']))
		{
			$options->setIsSaveOnlyChangedRights((bool)$data['isSaveOnlyChangedRights']);
		}

		if (isset($data['isSaveAccessRightsList']))
		{
			$options->setIsSaveAccessRightsList((bool)$data['isSaveAccessRightsList']);
		}

		if (isset($data['maxVisibleUserGroups']))
		{
			$options->setMaxVisibleUserGroups((int)$data['maxVisibleUserGroups']);
		}

		if (isset($data['searchContainerSelector']))
		{
			$options->setSearchContainerSelector((string)$data['searchContainerSelector']);
		}

		if (isset($data['additionalMembersParams']) && is_array($data['additionalMembersParams']))
		{
			$additionalMembersParams = AdditionalMemberOptions::tryFromArray($data['additionalMembersParams']);
			if ($additionalMembersParams !== null)
			{
				$options->setAdditionalMembersParams($additionalMembersParams);
			}
		}

		if (isset($data['userGroups']) && is_array($data['userGroups']))
		{
			$userGroups = [];
			foreach ($data['userGroups'] as $userGroupData)
			{
				$userGroup = UserGroup::tryFromArray($userGroupData);
				if ($userGroup !== null)
				{
					$userGroups[] = $userGroup;
				}
			}
			$options->setUserGroups($userGroups);
		}

		if (isset($data['accessRights']) && is_array($data['accessRights']))
		{
			$accessRights = [];
			foreach ($data['accessRights'] as $rightSectionData)
			{
				$rightSection = RightSection::tryFromArray($rightSectionData);
				if ($rightSection !== null)
				{
					$accessRights[] = $rightSection;
				}
			}
			$options->setAccessRights($accessRights);
		}

		return $options;
	}
}
