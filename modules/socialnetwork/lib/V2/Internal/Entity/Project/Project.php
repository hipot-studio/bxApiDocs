<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

/**
 * @method Project cloneWith(array $props)
 */
class Project extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?string $description = null,
		public readonly ?string $goal = null,
		public readonly ?Avatar $avatar = null,
		#[Validatable]
		public readonly ?User $owner = null,
		public readonly ?int $ownerId = null,
		public readonly ?bool $archived = null,
		public readonly ?int $chatId = null,
		public readonly ?string $color = null,
		public readonly ?PermissionCollection $permissions = null,
		public readonly ?PrivacyType $privacyType = null,
		public readonly ?ProjectDates $dates = null,
		public readonly ?ProjectTagCollection $tags = null,
		public readonly ?int $createdTs = null,
		public readonly ?int $activityTs = null,
		public readonly ?int $efficiency = null,
		public readonly ?int $numberOfMembers = null,
		public readonly ?int $numberOfModerators = null,
		public readonly ?MemberEntityCollection $members = null,
		public readonly ?MemberEntityCollection $moderatorMembers = null,
		// write-path fields
		public readonly ?array $rawPermissions = null,
		public readonly ?array $options = null,
		public readonly ?array $features = null,
		public readonly ?string $baseFeatureId = null,
		/** @var string[]|null */
		public readonly ?array $toggleableFeatures = null,
		public readonly ?array $tagNames = null,
		public readonly ?bool $publication = null,
		// computed flags
		public readonly ?bool $hasCollabers = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			name: static::mapString($props, 'name'),
			description: static::mapString($props, 'description'),
			goal: static::mapString($props, 'goal'),
			avatar: static::mapEntity($props, 'avatar', Avatar::class),
			owner: static::mapEntity($props, 'owner', User::class),
			ownerId: static::mapInteger($props, 'ownerId'),
			archived: static::mapBool($props, 'archived'),
			chatId: static::mapInteger($props, 'chatId'),
			color: static::mapString($props, 'color'),
			permissions: static::mapEntityCollection($props, 'permissions', PermissionCollection::class),
			privacyType: static::mapBackedEnum($props, 'privacyType', PrivacyType::class),
			dates: static::mapValueObject($props, 'dates', ProjectDates::class),
			tags: static::mapEntityCollection($props, 'tags', ProjectTagCollection::class),
			createdTs: static::mapInteger($props, 'createdTs'),
			activityTs: static::mapInteger($props, 'activityTs'),
			efficiency: static::mapInteger($props, 'efficiency'),
			numberOfMembers: static::mapInteger($props, 'numberOfMembers'),
			numberOfModerators: static::mapInteger($props, 'numberOfModerators'),
			members: static::mapEntityCollection($props, 'members', MemberEntityCollection::class),
			moderatorMembers: static::mapEntityCollection($props, 'moderatorMembers', MemberEntityCollection::class),
			options: static::mapArray($props, 'options'),
			features: static::mapArray($props, 'features'),
			baseFeatureId: static::mapString($props, 'baseFeatureId'),
			toggleableFeatures: static::mapArray($props, 'toggleableFeatures'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'goal' => $this->goal,
			'avatar' => $this->avatar?->toArray(),
			'ownerId' => $this->ownerId,
			'archived' => $this->archived,
			'chatId' => $this->chatId,
			'color' => $this->color,
			'owner' => $this->owner?->toArray(),
			'members' => $this->members?->toArray(),
			'moderatorMembers' => $this->moderatorMembers?->toArray(),
			'permissions' => $this->permissions?->toArray(),
			'options' => $this->options,
			'features' => $this->features,
			'baseFeatureId' => $this->baseFeatureId,
			'toggleableFeatures' => $this->toggleableFeatures,
			'privacyType' => $this->privacyType?->value,
			'dates' => $this->dates?->toArray(),
			'tags' => $this->tags?->toArray(),
			'createdTs' => $this->createdTs,
			'activityTs' => $this->activityTs,
			'efficiency' => $this->efficiency,
			'numberOfMembers' => $this->numberOfMembers,
			'numberOfModerators' => $this->numberOfModerators,
		];
	}
}
