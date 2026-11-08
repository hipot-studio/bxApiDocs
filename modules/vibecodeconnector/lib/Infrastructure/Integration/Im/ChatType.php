<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Im;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Chat\ExternalChat\Event\RegisterTypeEvent;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\AppChatDefaultRecent;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;

final class ChatType
{
	public static function onRegisterType(RegisterTypeEvent $event): EventResult
	{
		return new EventResult(
			EventResult::SUCCESS,
			[
					'type' => AppChatType::ENTITY_TYPE,
				'config' => new Config(
					hasOwnRecentSection: true,
					useDefaultRecentSection: Feature::isEnabled(AppChatDefaultRecent::class),
					permissions: [
						Action::Send->value => Chat::ROLE_MEMBER,
						Action::Extend->value => Chat::ROLE_NONE,
						Action::Rename->value => Chat::ROLE_NONE,
						Action::ChangeAvatar->value => Chat::ROLE_NONE,
						Action::ChangeDescription->value => Chat::ROLE_NONE,
						Action::ChangeColor->value => Chat::ROLE_NONE,
						Action::Kick->value => Chat::ROLE_NONE,
						Action::ChangeManagers->value => Chat::ROLE_NONE,
						Action::LeaveOwner->value => Chat::ROLE_NONE,
						Action::Mute->value => Chat::ROLE_MEMBER,
						Action::HideChat->value => Chat::ROLE_NONE,
						Action::Leave->value => Chat::ROLE_MEMBER,
					],
					isAutoJoinEnabled: false,
					requiresParentMembership: false,
				),
			],
		);
	}
}
