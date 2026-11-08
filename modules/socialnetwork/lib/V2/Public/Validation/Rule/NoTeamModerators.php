<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Validation\Rule;

use Attribute;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberCollection;

/**
 * Refuses a team asked for the moderator role, the way the moderator commands already do: the role is held
 * by people, and a team reaching this field is silently dropped further down, so the method would answer
 * success to a change it never applied.
 *
 * A department is left alone on purpose: it is dropped the same way, but it was dropped before a team became
 * a member type, and turning that answer into an error changes a contract this rule does not own.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NoTeamModerators implements PropertyValidationAttributeInterface
{
	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$result = new ValidationResult();

		if (!$propertyValue instanceof MemberCollection)
		{
			return $result;
		}

		foreach ($propertyValue as $moderator)
		{
			if ($moderator->type === MemberEntityType::Team)
			{
				$result->addError(new ValidationError('Only users can be assigned as moderators'));

				break;
			}
		}

		return $result;
	}
}
