<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Type\Contract\Arrayable;

class ProjectResponse implements Arrayable
{
	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?string $description = null,
		public readonly ?string $goal = null,
		public readonly ?array $avatar = null,
		public readonly ?int $ownerId = null,
		public readonly ?bool $archived = null,
		public readonly ?array $owner = null,
		public readonly ?int $chatId = null,
		public readonly ?string $color = null,
		public readonly ?array $members = null,
		public readonly ?array $moderatorMembers = null,
		public readonly ?array $permissions = null,
		public readonly ?array $features = null,
		public ?array $availableFeatures = null,
		public readonly ?string $baseFeatureId = null,
		/** @var string[]|null */
		public readonly ?array $toggleableFeatures = null,
		public readonly ?string $privacyType = null,
		public readonly ?array $dates = null,
		/** @var string[]|null */
		public readonly ?array $tags = null,
		public readonly ?int $createdTs = null,
		public readonly ?int $activityTs = null,
		public readonly ?int $numberOfMembers = null,
		public readonly ?bool $hasCollabers = null,
		public readonly ?bool $publication = null,
		public readonly ?array $options = null,
		public ?NotificationCatalogResponse $notificationCatalog = null,
	)
	{
	}

	public function toArray(): array
	{
		$result = [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'goal' => $this->goal,
			'avatar' => $this->avatar,
			'ownerId' => $this->ownerId,
			'archived' => $this->archived,
			'owner' => $this->owner,
			'chatId' => $this->chatId,
			'color' => $this->color,
			'members' => $this->members,
			'moderatorMembers' => $this->moderatorMembers,
			'permissions' => $this->permissions,
			'privacyType' => $this->privacyType,
			'dates' => $this->dates,
			'tags' => $this->tags,
			'createdTs' => $this->createdTs,
			'activityTs' => $this->activityTs,
			'numberOfMembers' => $this->numberOfMembers,
			'hasCollabers' => $this->hasCollabers,
		];

		if ($this->features !== null)
		{
			$result['features'] = $this->features;
		}

		if ($this->availableFeatures !== null)
		{
			$result['availableFeatures'] = $this->availableFeatures;
		}

		if ($this->baseFeatureId !== null)
		{
			$result['baseFeatureId'] = $this->baseFeatureId;
		}

		if ($this->toggleableFeatures !== null)
		{
			$result['toggleableFeatures'] = $this->toggleableFeatures;
		}

		if ($this->options !== null)
		{
			$result['options'] = $this->options;
		}

		if ($this->publication !== null)
		{
			$result['publication'] = $this->publication;
		}

		if ($this->notificationCatalog !== null)
		{
			$result['notificationCatalog'] = $this->notificationCatalog->toArray();
		}

		return $result;
	}

	public function setAvailableFeatures(?array $availableFeatures): void
	{
		$this->availableFeatures = $availableFeatures;
	}

	public function setNotificationCatalog(?NotificationCatalogResponse $catalog): void
	{
		$this->notificationCatalog = $catalog;
	}
}
