<?php

namespace Bitrix\Crm\Controller\ClientTab;

use Bitrix\Crm\Binding\OwnerBindingFilter;
use Bitrix\Crm\Controller\Autorun\Dto\Progress;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\AbstractClientBindingCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\UnbindCompaniesCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\UnbindContactsCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Application;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Collection;

/**
 * Group action "Unbind" of the contacts and companies tabs of a card, the crm.autorun protocol.
 * Only the ids checked on the page are processed, hidden ones included. One portion is one unbind command,
 * that is one save of the owner. Previous portions are not rolled back when a portion fails.
 */
final class Unbind extends Base
{
	private const PORTION = AbstractClientBindingCommand::MAX_IDS;
	private const SESSION_PREFIX = 'crm_batch_client_tab_unbind';

	private SessionLocalStorage $dataStorage;
	private SessionLocalStorage $progressStorage;

	protected function init(): void
	{
		parent::init();

		$this->dataStorage = Application::getInstance()->getLocalSession(self::SESSION_PREFIX . '_data');
		$this->progressStorage = Application::getInstance()->getLocalSession(self::SESSION_PREFIX . '_progress');
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::AJAX);

		return $filters;
	}

	/**
	 * crm.api.clienttab.unbind.prepare
	 * params: {gridId, entityTypeId: child type, entityIds: int[], extras: {ownerEntityTypeId, ownerEntityId}}
	 */
	public function prepareAction(array $params): ?array
	{
		$childEntityTypeId = (int)($params['entityTypeId'] ?? 0);
		$extras = is_array($params['extras'] ?? null) ? $params['extras'] : [];
		$ownerEntityTypeId = (int)($extras['ownerEntityTypeId'] ?? 0);
		$ownerEntityId = (int)($extras['ownerEntityId'] ?? 0);

		if (!OwnerBindingFilter::isSupported($childEntityTypeId, $ownerEntityTypeId))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($ownerEntityTypeId));

			return null;
		}

		$gridId = (string)($params['gridId'] ?? '');
		if ($gridId === '')
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('gridId'));

			return null;
		}

		// there is no "for all": without the checked ids nothing is unbound
		$ids = is_array($params['entityIds'] ?? null) ? $params['entityIds'] : [];
		Collection::normalizeArrayValuesByInt($ids, false);
		$ids = array_values(array_unique($ids));
		if (empty($ids))
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('entityIds'));

			return null;
		}

		if (!$this->canUpdateOwner($ownerEntityTypeId, $ownerEntityId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$hash = md5(implode(':', [
			\CCrmOwnerType::ResolveName($ownerEntityTypeId),
			$ownerEntityId,
			\CCrmOwnerType::ResolveName($childEntityTypeId),
			mb_strtoupper($gridId),
			implode(',', $ids),
		]));

		$this->dataStorage->set($hash, [
			'ownerEntityTypeId' => $ownerEntityTypeId,
			'ownerEntityId' => $ownerEntityId,
			'childEntityTypeId' => $childEntityTypeId,
			'ids' => $ids,
		]);
		unset($this->progressStorage[$hash]);

		return ['hash' => $hash];
	}

	/**
	 * crm.api.clienttab.unbind.process
	 * One portion per call. The right to update the owner is checked on each call.
	 */
	public function processAction(array $params): ?array
	{
		$hash = (string)($params['hash'] ?? '');
		if ($hash === '')
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('hash'));

			return null;
		}

		$data = $this->dataStorage->get($hash);
		if (!is_array($data) || empty($data['ids']) || !is_array($data['ids']))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		$ownerEntityTypeId = (int)$data['ownerEntityTypeId'];
		$ownerEntityId = (int)$data['ownerEntityId'];
		$childEntityTypeId = (int)$data['childEntityTypeId'];
		$ids = array_map('intval', $data['ids']);

		if (!$this->canUpdateOwner($ownerEntityTypeId, $ownerEntityId))
		{
			$this->clear($hash);
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$progress = new Progress(is_array($this->progressStorage->get($hash)) ? $this->progressStorage->get($hash) : []);
		$progress->totalCount = count($ids);

		$portion = array_slice($ids, $progress->processedCount, self::PORTION);
		if (!empty($portion))
		{
			$owner = new ItemId(EntityType::fromId($ownerEntityTypeId), $ownerEntityId);
			$userId = $this->getCurrentUserId();
			$command = $childEntityTypeId === \CCrmOwnerType::Contact
				? new UnbindContactsCommand($owner, $portion, $userId)
				: new UnbindCompaniesCommand($owner, $portion, $userId)
			;

			$this->registerResult($progress, $command->run(), $portion, $childEntityTypeId);
			$progress->processedCount += count($portion);
			$progress->lastId = (int)end($portion);
		}

		$isCompleted = $progress->processedCount >= $progress->totalCount;
		if ($isCompleted)
		{
			$this->clear($hash);
		}
		else
		{
			$state = $progress->toArray();
			$this->progressStorage->set($hash, [
				'lastId' => $state['lastId'] ?? 0,
				'processedCount' => $state['processedCount'] ?? 0,
				'totalCount' => $state['totalCount'] ?? null,
			]);
		}

		$response = [
			'status' => $isCompleted ? 'COMPLETED' : 'PROGRESS',
			'processedItems' => $progress->processedCount,
			'totalItems' => $progress->totalCount,
		];
		if ($progress->hasErrors())
		{
			$response['errors'] = $progress->getErrors();
		}

		return $response;
	}

	/**
	 * crm.api.clienttab.unbind.cancel
	 */
	public function cancelAction(array $params): ?array
	{
		$hash = (string)($params['hash'] ?? '');
		if ($hash === '')
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('hash'));

			return null;
		}

		$this->clear($hash);

		return ['hash' => $hash];
	}

	/**
	 * Errors of the step only: the batch manager sums them up itself.
	 *
	 * @param int[] $portion
	 */
	private function registerResult(Progress $progress, Result $result, array $portion, int $childEntityTypeId): void
	{
		$errorsById = [];
		foreach ($result->getErrors() as $error)
		{
			$id = (int)($error->getCustomData()['id'] ?? 0);
			if ($id > 0)
			{
				$errorsById[] = [$id, $error->getMessage(), $error->getCode()];

				continue;
			}

			// the whole portion failed
			$message = $error->getMessage() !== ''
				? $error->getMessage()
				: (string)Loc::getMessage('CRM_CLIENT_TAB_UNBIND_PORTION_FAILED');
			foreach ($portion as $portionId)
			{
				$errorsById[] = [$portionId, $message, $error->getCode()];
			}
		}

		if (empty($errorsById))
		{
			foreach ($portion as $id)
			{
				$progress->addSuccessId($id);
			}

			return;
		}

		$titles = $this->getTitles($childEntityTypeId, array_unique(array_column($errorsById, 0)));
		$failedIds = [];
		foreach ($errorsById as [$id, $message, $code])
		{
			$failedIds[$id] = true;
			$progress->addErrorId($id);
			$progress->addError(new Error($message, $code, ['id' => $id, 'info' => $titles[$id]]));
		}

		foreach ($portion as $id)
		{
			if (!isset($failedIds[$id]))
			{
				$progress->addSuccessId($id);
			}
		}
	}

	/**
	 * A readable item is named by its heading with a link, a hidden one gets an anonymous caption without a link.
	 * Data of hidden items is not selected.
	 *
	 * @param int[] $ids
	 * @return array<int, array{title: string, showUrl?: string}>
	 */
	private function getTitles(int $childEntityTypeId, array $ids): array
	{
		$titles = [];
		$factory = Container::getInstance()->getFactory($childEntityTypeId);
		if ($factory)
		{
			$items = $factory->getItemsFilteredByPermissions(
				[
					'select' => ['*'],
					'filter' => ['@ID' => array_values($ids)],
				],
				$this->getCurrentUserId(),
			);
			$router = Container::getInstance()->getRouter();
			foreach ($items as $item)
			{
				$titles[$item->getId()] = [
					'title' => $item->getHeading(),
					'showUrl' => (string)$router->getItemDetailUrl($childEntityTypeId, $item->getId()),
				];
			}
		}

		$hiddenCaption = (string)\CCrmViewHelper::GetHiddenEntityCaption($childEntityTypeId);
		foreach ($ids as $id)
		{
			$titles[$id] ??= ['title' => $hiddenCaption];
		}

		return $titles;
	}

	private function canUpdateOwner(int $ownerEntityTypeId, int $ownerEntityId): bool
	{
		return $ownerEntityId > 0
			&& Container::getInstance()
				->getUserPermissions($this->getCurrentUserId())
				->item()
				->canUpdateItemIdentifier(new ItemIdentifier($ownerEntityTypeId, $ownerEntityId))
		;
	}

	private function clear(string $hash): void
	{
		unset($this->dataStorage[$hash], $this->progressStorage[$hash]);
	}

	private function getCurrentUserId(): int
	{
		return Container::getInstance()->getContext()->getUserId();
	}
}
