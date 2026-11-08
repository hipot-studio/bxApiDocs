<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Structure;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberCollection;

/**
 * Keeps DTO validation aligned with raw access-code validation in CollabService.
 *
 * The rule reads the collection as a whole, so it may guard only a composition where every member is being
 * attached. Where the collection carries the desired state and the attached part is a diff of it - updating
 * a project - the groups keep the rule out, and CollabService answers for the diff it builds.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TeamsSupported implements PropertyValidationAttributeInterface, ValidateByGroupInterface
{
	public const ERROR_TEAMS_NOT_AVAILABLE = Structure::ERROR_TEAMS_NOT_AVAILABLE;

	public function __construct(
		protected array $groups = [],
	)
	{
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$result = new ValidationResult();

		if (!$propertyValue instanceof MemberCollection)
		{
			return $result;
		}

		foreach ($propertyValue as $member)
		{
			if ($member->type !== MemberEntityType::Team)
			{
				continue;
			}

			if (!$this->areTeamsSupported())
			{
				$result->addError(new ValidationError(
					Loc::getMessage('SONET_V2_VALIDATION_TEAMS_NOT_AVAILABLE') ?? self::ERROR_TEAMS_NOT_AVAILABLE,
					self::ERROR_TEAMS_NOT_AVAILABLE,
				));
			}

			break;
		}

		return $result;
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	protected function areTeamsSupported(): bool
	{
		return Structure::isTeamsAvailable();
	}
}
