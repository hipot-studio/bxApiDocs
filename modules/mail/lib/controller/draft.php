<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Repository\DraftRepository;
use Bitrix\Mail\Internal\Service\Draft\DraftService;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Intranet;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class Draft extends Base
{
	private const ERROR_FEATURE_DISABLED = 'FEATURE_DISABLED';

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		if (Loader::includeModule('intranet'))
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	public function configureActions(): array
	{
		$postFilters = [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])];
		$readFilters = [
			'-prefilters' => [ActionFilter\Csrf::class],
		];

		return [
			'config' => $readFilters,
			'save' => ['+prefilters' => $postFilters],
			'get' => $readFilters,
			'list' => $readFilters,
			'delete' => ['+prefilters' => $postFilters],
			'deleteMany' => ['+prefilters' => $postFilters],
		];
	}

	public function configAction(): array
	{
		return [
			'available' => Feature::isInternalDraftsAvailable(),
			'retentionDays' => DraftRepository::RETENTION_DAYS,
		];
	}

	public function saveAction(array $snapshot, ?int $draftId = null, ?int $revision = null): ?array
	{
		return $this->executeEnabledAction(
			fn(): Result => (new DraftService())->save(
				userId: $this->getUserId(),
				contextType: DraftTable::CONTEXT_MAIL,
				draftId: $draftId,
				revision: $revision,
				snapshotData: $snapshot,
			),
		);
	}

	public function getAction(int $draftId): ?array
	{
		return $this->executeEnabledAction(
			fn(): Result => (new DraftService())->get($this->getUserId(), $draftId, DraftTable::CONTEXT_MAIL),
		);
	}

	public function listAction(int $page = 1, int $pageSize = 20, string $search = ''): ?array
	{
		return $this->executeEnabledAction(
			fn(): Result => (new DraftService())->list($this->getUserId(), $page, $pageSize, $search),
		);
	}

	/**
	 * A compose form reports the revision and the identity it was showing, and then the draft is dropped
	 * only while it still is that draft: work another form has written into it since was never on screen.
	 * The drafts list reports neither and drops a row by its identifier, as it always did.
	 */
	public function deleteAction(int $draftId, ?int $revision = null, ?string $clientId = null): ?array
	{
		return $this->executeEnabledAction(
			fn(): Result => (new DraftService())->delete(
				$this->getUserId(),
				$draftId,
				DraftTable::CONTEXT_MAIL,
				$revision,
				$clientId,
			),
		);
	}

	public function deleteManyAction(array $draftIds): ?array
	{
		return $this->executeEnabledAction(
			fn(): Result => (new DraftService())->deleteMany(
				$this->getUserId(),
				$draftIds,
				DraftTable::CONTEXT_MAIL,
			),
		);
	}

	private function executeEnabledAction(callable $action): ?array
	{
		if (!Feature::isInternalDraftsAvailable())
		{
			$this->addError(new Error('Feature is unavailable.', self::ERROR_FEATURE_DISABLED));

			return null;
		}

		return $this->executeAction($action);
	}

	private function executeAction(callable $action): ?array
	{
		$result = $action();
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError(new Error('Draft operation failed.', (string)$error->getCode()));
			}

			return null;
		}

		return $result->getData();
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}

