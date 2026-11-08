<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\Service\DashboardGroupService;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Controller\Dashboard as DashboardController;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Copies a dashboard the way the web copy does (Controller\Dashboard::copyAction()), except that the copy
 * is a draft: a published copy could not be edited by an agent either.
 *
 * Only a dashboard the caller may open is copied (DashboardViewRule: a draft needs the edit right, as in the grid);
 * one they may not is refused the way a missing one is.
 *
 * The copy takes the tags, scopes, url parameters and group bindings of the source; the rights of the source
 * are carried by the handler of biconnector::onAfterCopyDashboard, for the roles of the caller only, as on
 * the web. The event goes before the group bindings are copied, on the web alike.
 *
 * Data: `id`, `name`, `published` — the shape create_dashboard answers with.
 */
final class CopyDashboardOperation
{
	// The title of a copy is the phrase of the web copy.
	private const COPY_TITLE_PHRASE = 'BICONNECTOR_CONTROLLER_DASHBOARD_COPIED_DASHBOARD_TITLE';

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
	}

	public function execute(int $dashboardId, Actor $actor): Result
	{
		$result = new Result();

		if (!Feature::isBuilderEnabled())
		{
			return $result->addError(new Error(
				'BI Builder feature is not enabled on this portal.',
				FailureCode::FeatureDisabled->value,
			));
		}

		$source = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		$accessController = ($this->accessControllerFactory)($actor->userId);
		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		// A dashboard the caller cannot open is answered as a missing one: otherwise copying would tell
		// the drafts of other groups apart from ids that do not exist.
		if (!$source || !$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			return $result->addError(new Error('Dashboard not found.', FailureCode::NotFound->value));
		}

		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $accessItem))
		{
			return $result->addError(new Error('Access denied.', FailureCode::AccessDenied->value));
		}

		$context = ['stage' => 'copy_dashboard', 'dashboard_id' => $dashboardId, 'user_id' => $actor->userId];
		$externalId = (int)$source->getExternalId();
		if ($externalId <= 0)
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' has no external id (not synced yet)')],
				['stage' => 'copy_dashboard.no_external_id'] + $context,
			);

			return Refusal::error(FailureCode::Unavailable, Refusal::UNAVAILABLE_DASHBOARD_MESSAGE);
		}

		$response = IntegratorFactory::getInstance()->copyDashboard($externalId, self::makeCopyTitle($source));
		$copied = $response->getData();
		if ($response->hasErrors() || !is_array($copied) || !$copied)
		{
			return Refusal::unavailableDashboard($response->getErrors(), $context);
		}

		$copyExternalId = (int)($copied['id'] ?? 0);
		// Superset publishes the copy of a published dashboard, while the copy here is a draft: left published
		// there, it would be refused by the draft rule on its first edit.
		if (($copied['published'] ?? true) && !$this->unpublishRemote($copyExternalId, $context))
		{
			// No local row is written for it, so the copy is not left in Superset for every retry to add one more.
			$this->deleteRemote($copyExternalId, $context);

			return Refusal::error(FailureCode::Unavailable, Refusal::UNAVAILABLE_DASHBOARD_MESSAGE);
		}

		$title = (string)($copied['title'] ?? '');
		$addResult = SupersetDashboardTable::add([
			'EXTERNAL_ID' => $copyExternalId,
			'TITLE' => $title,
			'SOURCE_ID' => $dashboardId,
			'APP_ID' => $source->getAppId(),
			'TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
			'STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
			'CREATED_BY_ID' => $actor->userId,
		]);
		if (!$addResult->isSuccess())
		{
			AiToolsLogger::logErrors($addResult->getErrors(), ['stage' => 'copy_dashboard.local_add'] + $context);
			$this->deleteRemote($copyExternalId, $context);

			return Refusal::error(FailureCode::Unavailable, Refusal::UNAVAILABLE_DASHBOARD_MESSAGE);
		}
		$copyId = (int)$addResult->getId();

		(new Event('biconnector', 'onAfterCopyDashboard', [
			'dashboardId' => $copyId,
			'sourceDashboardId' => $dashboardId,
			'createdById' => $actor->userId,
		]))->send();

		$this->copyBindings($source, $copyId);

		return $result->setData([
			'id' => $copyId,
			'name' => $title,
			'published' => false,
		]);
	}

	/**
	 * Whether Superset confirmed the copy is not published, the way the web confirms a move to draft.
	 */
	private function unpublishRemote(int $externalId, array $context): bool
	{
		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, ['published' => false]);
		$changed = $response->getData();
		if (
			$response->getStatus() === IntegratorResponse::STATUS_OK
			&& !$response->hasErrors()
			&& is_array($changed)
			&& ($changed['published'] ?? null) === false
		)
		{
			return true;
		}

		AiToolsLogger::logErrors(
			$response->getErrors() ?: [new Error('Copy ' . $externalId . ' stays published in Superset')],
			['stage' => 'copy_dashboard.unpublish'] + $context,
		);

		return false;
	}

	private function deleteRemote(int $externalId, array $context): void
	{
		$response = IntegratorFactory::getInstance()->deleteDashboard([$externalId]);
		if ($response->getStatus() !== IntegratorResponse::STATUS_OK || $response->hasErrors())
		{
			AiToolsLogger::logErrors(
				$response->getErrors() ?: [new Error('Copy ' . $externalId . ' is left in Superset')],
				['stage' => 'copy_dashboard.delete_unpublished'] + $context,
			);
		}
	}

	private function copyBindings(SupersetDashboard $source, int $copyId): void
	{
		foreach ($source->fillTags() as $tag)
		{
			SupersetDashboardTagTable::add([
				'TAG_ID' => $tag->getId(),
				'DASHBOARD_ID' => $copyId,
			]);
		}

		$scopes = ScopeService::getInstance()->getDashboardScopes($source->getId());
		ScopeService::getInstance()->saveDashboardScopes($copyId, $scopes);

		$copy = SupersetDashboardTable::getById($copyId)->fetchObject();
		if ($copy)
		{
			$paramCodes = $source->fillUrlParams()->getCodeList();
			(new UrlParameter\Service($copy))->saveDashboardParams($paramCodes, $scopes);
		}

		$groupIds = $source->fillGroups()?->getIdList() ?? [];
		DashboardGroupService::saveDashboardGroupBindings($copyId, $groupIds);

		DashboardManager::notifyDashboardListChanged();
	}

	private static function makeCopyTitle(SupersetDashboard $source): string
	{
		Loc::loadMessages((new \ReflectionClass(DashboardController::class))->getFileName());

		return Loc::getMessage(self::COPY_TITLE_PHRASE, ['#DASHBOARD_TITLE#' => $source->getTitle()]) ?? '';
	}
}
