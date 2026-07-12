<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\Factory;

use Bitrix\Socialnetwork\V2\Internal\Exceptions\UnknownHandlerException;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\ConvertChat;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\HandlerInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SendConverterEvent;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SendConverterPushEvent;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SetProjectOptions;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SyncProjectChatMembers;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\StoreFeatureStatesOnConvert;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateFeaturePermissions;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateFeatures;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateGroupEventsChat;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateGroupFields;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateGroupTasksChat;

class HandlerFactory implements HandlerFactoryInterface
{
	/**
	 * @throws UnknownHandlerException
	 */
	public function create(string $class): HandlerInterface
	{
		return match ($class)
		{
			ConvertChat::class => new ConvertChat(),
			SendConverterEvent::class => new SendConverterEvent(),
			SendConverterPushEvent::class => new SendConverterPushEvent(),
			SetProjectOptions::class => new SetProjectOptions(),
			SyncProjectChatMembers::class => new SyncProjectChatMembers(),
			StoreFeatureStatesOnConvert::class => new StoreFeatureStatesOnConvert(),
			UpdateFeaturePermissions::class => new UpdateFeaturePermissions(),
			UpdateFeatures::class => new UpdateFeatures(),
			UpdateGroupFields::class => new UpdateGroupFields(),
			UpdateGroupTasksChat::class => new UpdateGroupTasksChat(),
			UpdateGroupEventsChat::class => new UpdateGroupEventsChat(),
			default => throw new UnknownHandlerException(sprintf('Unknown handler: %s', $class)),
		};
	}
}
