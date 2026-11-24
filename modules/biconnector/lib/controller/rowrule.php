<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Public\Command\RowRule\AddRowRuleCommand;
use Bitrix\BIConnector\Public\Command\RowRule\DeleteRowRuleCommand;
use Bitrix\BIConnector\Public\Command\RowRule\UpdateRowRuleCommand;
use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleProvider;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\ActionFilter\RowRuleAccess;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * API of the row rules: `biconnector.rowrule.*`.
 *
 * The prefilters check the feature and the right; the work itself belongs to the commands and providers.
 */
class RowRule extends Controller
{
	public const ERROR_SAVE_FAILED = 'ROW_RULE_SAVE_FAILED';
	public const ERROR_DELETE_FAILED = 'ROW_RULE_DELETE_FAILED';
	public const ERROR_LOAD_FAILED = 'ROW_RULE_LOAD_FAILED';

	private const PHRASE_SAVE_FAILED = 'BICONNECTOR_CONTROLLER_ROW_RULE_ERROR_SAVE_FAILED';
	private const PHRASE_DELETE_FAILED = 'BICONNECTOR_CONTROLLER_ROW_RULE_ERROR_DELETE_FAILED';
	private const PHRASE_LOAD_FAILED = 'BICONNECTOR_CONTROLLER_ROW_RULE_ERROR_LOAD_FAILED';

	protected function getDefaultPreFilters(): array
	{
		$additionalFilters = [
			new BIConstructorAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			...$additionalFilters,
			new RowRuleAccess(),
		];
	}

	/**
	 * @param array $filter `presetId`, `tableCode`, `createdById`, `updatedById`, `isUsed`.
	 * @param array $order `field`, `direction`.
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return array{items: array[], total: int}|null
	 */
	public function listAction(array $filter = [], array $order = [], int $offset = 0, int $limit = 20): ?array
	{
		return $this->runSafely(
			fn(): array => $this->createRuleProvider()->getList(
				[
					'filter' => $filter,
					'order' => $order,
					'offset' => $offset,
					'limit' => $limit,
				],
				$this->getCurrentUserId(),
			),
			self::ERROR_LOAD_FAILED,
			self::PHRASE_LOAD_FAILED,
		);
	}

	public function getAction(int $id): ?array
	{
		$result = $this->runResult(
			fn(): Result => $this->createRuleProvider()->get($id),
			self::ERROR_LOAD_FAILED,
			self::PHRASE_LOAD_FAILED,
		);

		return $result?->getData()['rule'];
	}

	public function addAction(array $fields): ?array
	{
		$command = new AddRowRuleCommand($fields, $this->getCurrentUserId());
		$result = $this->runResult($command->run(...), self::ERROR_SAVE_FAILED, self::PHRASE_SAVE_FAILED);

		return $result?->getData()['rule'];
	}

	public function updateAction(int $id, array $fields): ?array
	{
		$command = new UpdateRowRuleCommand($id, $fields, $this->getCurrentUserId());
		$result = $this->runResult($command->run(...), self::ERROR_SAVE_FAILED, self::PHRASE_SAVE_FAILED);

		return $result?->getData()['rule'];
	}

	/**
	 * @return array{id: int}|null
	 */
	public function deleteAction(int $id): ?array
	{
		$command = new DeleteRowRuleCommand($id);
		$result = $this->runResult($command->run(...), self::ERROR_DELETE_FAILED, self::PHRASE_DELETE_FAILED);

		return $result !== null ? ['id' => (int)$result->getData()['id']] : null;
	}

	protected function createRuleProvider(): RowRuleProvider
	{
		return new RowRuleProvider();
	}

	/**
	 * @param callable(): Result $work
	 *
	 * @return Result|null Null when the work failed; its errors are added to the controller.
	 */
	private function runResult(callable $work, string $failureCode, string $failurePhrase): ?Result
	{
		$result = $this->runSafely($work, $failureCode, $failurePhrase);
		if ($result === null)
		{
			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result;
	}

	/**
	 * Runs the work; an unexpected failure becomes one user-facing error of the given code.
	 *
	 * @return mixed The value of the work, null when it failed.
	 */
	private function runSafely(callable $work, string $failureCode, string $failurePhrase): mixed
	{
		try
		{
			return $work();
		}
		catch (\Throwable $exception)
		{
			// The text of a database failure is not for the client: it goes to the log only.
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$this->addError(new UserFacingError((string)Loc::getMessage($failurePhrase), $failureCode));

			return null;
		}
	}

	private function getCurrentUserId(): int
	{
		return (int)($this->getCurrentUser() ?? CurrentUser::get())->getId();
	}
}
