<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Socialnetwork\V2\Internal\Validation\ProjectValidationGroup;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Validation\Rule\NullOr;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMemberMapper;

class Project implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		#[NullOr(PositiveNumber::class)]
		#[PositiveNumber(groups: [ProjectValidationGroup::Update])]
		public readonly ?int $id = null,
		#[NotEmpty(allowZero: true, groups: [ProjectValidationGroup::Add])]
		#[NullOr(NotEmpty::class, allowZero: true, groups: [ProjectValidationGroup::Copy, ProjectValidationGroup::Update])]
		public readonly ?string $name = null,
		public readonly ?string $description = null,
		public readonly ?string $goal = null,
		#[NullOr(PositiveNumber::class)]
		public readonly ?int $ownerId = null,
		#[NullOr(PositiveNumber::class)]
		public readonly ?int $chatId = null,
		public readonly ?PrivacyType $privacyType = null,
		public readonly ?MemberCollection $members = null,
		public readonly ?MemberCollection $moderatorMembers = null,
		public readonly ?array $features = null,
		public readonly ?string $baseFeatureId = null,
		public readonly ?array $permissions = null,
		public readonly ?array $options = null,
		public readonly ?ProjectDates $dates = null,
		/** @var string[]|null */
		public readonly ?array $tags = null,
		public readonly ?bool $publication = null,
		public readonly ?Avatar $avatar = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		$dates = self::mapDates($props['dates'] ?? null);
		$memberMapper = new ProjectMemberMapper();
		$avatar = null;
		if (array_key_exists('avatar', $props) && $props['avatar'] !== null)
		{
			$avatarValue = $props['avatar'];
			$avatar = $avatarValue instanceof Avatar
				? $avatarValue
				: Avatar::mapFromArray(
					is_array($avatarValue) ? $avatarValue : [],
				);
		}

		return new static(
			id: static::mapInteger($props, 'id'),
			name: static::mapString($props, 'name'),
			description: static::mapString($props, 'description'),
			goal: static::mapString($props, 'goal'),
			ownerId: static::mapInteger($props, 'ownerId'),
			chatId: static::mapInteger($props, 'chatId'),
			privacyType: static::mapBackedEnum($props, 'privacyType', PrivacyType::class),
			members: $memberMapper->mapRequestCollection($props['members'] ?? null),
			moderatorMembers: $memberMapper->mapRequestCollection($props['moderatorMembers'] ?? null),
			features: static::mapArray($props, 'features'),
			baseFeatureId: static::mapString($props, 'baseFeatureId'),
			permissions: static::mapArray($props, 'permissions'),
			options: static::mapArray($props, 'options'),
			dates: $dates,
			tags: static::mapArray($props, 'tags'),
			publication: static::mapBool($props, 'publication'),
			avatar: $avatar,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'goal' => $this->goal,
			'ownerId' => $this->ownerId,
			'chatId' => $this->chatId,
			'privacyType' => $this->privacyType?->value,
			'members' => $this->members?->toArray(),
			'moderatorMembers' => $this->moderatorMembers?->toArray(),
			'features' => $this->features,
			'baseFeatureId' => $this->baseFeatureId,
			'permissions' => $this->permissions,
			'options' => $this->options,
			'dates' => $this->dates?->toArray(),
			'tags' => $this->tags,
			'publication' => $this->publication,
			'avatar' => $this->avatar?->toArray(),
		];
	}

	private static function mapDates(mixed $dates): ?ProjectDates
	{
		if (!is_array($dates))
		{
			return null;
		}

		if (!array_key_exists('startTs', $dates) && !array_key_exists('finishTs', $dates))
		{
			return null;
		}

		return ProjectDates::mapFromArray($dates);
	}
}
