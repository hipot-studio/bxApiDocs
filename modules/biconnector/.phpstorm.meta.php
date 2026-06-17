<?php

namespace PHPSTORM_META
{
	registerArgumentsSet(
		'bitrix_biconnector_locator_codes',
		'biconnector.service.dashboardInfo.fileCleanup',
		'biconnector.service.dashboardDiscussionChat',
		'biconnector.service.dashboardDiscussionChatAccessSync',
		'biconnector.service.ahaMomentSpotlightResolver',
		'biconnector.provider.dashboardDetailInfo',
	);

	expectedArguments(\Bitrix\Main\DI\ServiceLocator::get(), 0, argumentsSet('bitrix_biconnector_locator_codes'));

	override(
		\Bitrix\Main\DI\ServiceLocator::get(0),
		map([
			'biconnector.service.dashboardInfo.fileCleanup' => \Bitrix\BIConnector\Internal\Services\DashboardInfo\FileCleanupService::class,
			'biconnector.service.dashboardDiscussionChat' => \Bitrix\BIConnector\Internal\Integration\Im\DashboardDiscussionChatService::class,
			'biconnector.service.dashboardDiscussionChatAccessSync' => \Bitrix\BIConnector\Internal\Integration\Im\DashboardDiscussionChatAccessSyncService::class,
			'biconnector.service.ahaMomentSpotlightResolver' => \Bitrix\BIConnector\Public\Services\AhaMoment\AhaMomentSpotlightResolver::class,
			'biconnector.provider.dashboardDetailInfo' => \Bitrix\BIConnector\Public\Provider\DashboardDetailInfoProvider::class,
		]),
	);
}