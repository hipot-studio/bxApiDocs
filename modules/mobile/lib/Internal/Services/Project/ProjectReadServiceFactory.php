<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;

final class ProjectReadServiceFactory
{
	public function __construct(
		private readonly ProjectChatSettingsService $projectChatSettingsService,
		private readonly ProjectProvider $projectProvider,
	)
	{
	}

	public function createReadService(int $currentUserId): ProjectReadService
	{
		return new ProjectReadService(
			currentUserId: $currentUserId,
			projectChatSettingsService: $this->projectChatSettingsService,
			projectProvider: $this->projectProvider,
		);
	}
}
