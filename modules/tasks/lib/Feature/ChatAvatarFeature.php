<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Feature;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\Infrastructure\Agent\ChatAvatarFeatureInitializer;

final class ChatAvatarFeature extends AbstractFlag
{
	public function __construct(private readonly ?ChatAvatarFeatureInitializer $initializer = null)
	{
	}

	public function enabledByDefault(): bool
	{
		return Option::get('tasks', 'chat_avatar_feature', 'N') === 'Y';
	}

	public static function getExplicitValue(): ?bool
	{
		return FeatureFlagTable::getByPrimary(self::class, ['select' => ['ENABLED']])
			->fetchObject()
			?->getEnabled()
		;
	}

	protected function onEnable(): void
	{
		try
		{
			if (!Feature::isEnabled(self::class))
			{
				throw new SystemException('Task chat avatars are disabled by a feature rule.');
			}

			($this->initializer ?? new ChatAvatarFeatureInitializer())->activateForRelease();
		}
		catch (\Throwable $exception)
		{
			try
			{
				Feature::disable(self::class);
			}
			catch (\Throwable)
			{
				// Preserve the original publication or scheduling failure.
			}

			throw $exception;
		}
	}

	protected function onDisable(): void
	{
		($this->initializer ?? new ChatAvatarFeatureInitializer())->onFeatureDisabled();
	}
}
