<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Delete;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Collab\Integration\Note\Service\BindingService;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\Control\Command\DeleteCommand;
use Bitrix\Socialnetwork\Control\Handler\Delete\DeleteHandlerInterface;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Note\Public\Command\DeleteCollectionCommand;

/**
 * Phase 2 (P2.T2): каскадное удаление note-коллекции при удалении коллаба.
 *
 * Direction B: socialnetwork вызывает публичный API note под guarded includeModule.
 * Документы коллекции уходят в корзину note (механизм DeleteCollectionCommand),
 * затем очищается привязка. Defensive: ошибка не прерывает удаление коллаба;
 * привязка очищается даже если коллекция уже удалена.
 */
class DeleteNoteCollectionHandler implements DeleteHandlerInterface
{
	private const NOTE_MODULE_ID = 'note';

	public function delete(DeleteCommand $command, Workgroup $entityBefore): HandlerResult
	{
		$result = new HandlerResult();

		// Guarded optional dependency: без note — расширенное поведение отключается.
		if (!Loader::includeModule(self::NOTE_MODULE_ID))
		{
			return $result;
		}

		$collabId = $command->getId();
		if ($collabId <= 0)
		{
			return $result;
		}

		$bindingService = Container::getInstance()->get(BindingService::class);

		$collectionId = $bindingService->findCollectionIdByCollab($collabId);
		if ($collectionId === null)
		{
			// Нет привязки — нечего удалять.
			return $result;
		}

		try
		{
			(new DeleteCollectionCommand($collectionId, $command->getInitiatorId()))->run();
		}
		catch (\Throwable $e)
		{
			// Defensive: ошибка удаления коллекции не должна валить удаление коллаба.
			\CEventLog::Add([
				'SEVERITY' => 'WARNING',
				'AUDIT_TYPE_ID' => 'COLLAB_NOTE_COLLECTION_DELETE_FAILED',
				'MODULE_ID' => 'socialnetwork',
				'ITEM_ID' => $collabId,
				'DESCRIPTION' => $e->getMessage(),
			]);
		}

		// Привязка очищается даже если коллекция уже удалена.
		$bindingService->deleteByCollab($collabId);

		return $result;
	}
}
