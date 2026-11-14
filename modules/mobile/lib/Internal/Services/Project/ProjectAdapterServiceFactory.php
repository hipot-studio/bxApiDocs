<?php

namespace Bitrix\Mobile\Internal\Services\Project;

final class ProjectAdapterServiceFactory
{
	public function __construct(
		private readonly ProjectSettingsValidator $settingsValidator,
		private readonly OperationResultErrorResolver $errorResolver,
		private readonly ProjectChatSettingsService $projectChatSettingsService,
		private readonly ProjectLegacyUpdateService $projectLegacyUpdateService,
		private readonly ProjectDatesReader $projectDatesReader,
	)
	{
	}

	public function createAdapterService(int $currentUserId): ProjectAdapterService
	{
		return new ProjectAdapterService(
			currentUserId: $currentUserId,
			settingsValidator: $this->settingsValidator,
			errorResolver: $this->errorResolver,
			projectChatSettingsService: $this->projectChatSettingsService,
			projectLegacyUpdateService: $this->projectLegacyUpdateService,
			projectDatesReader: $this->projectDatesReader,
		);
	}
}
