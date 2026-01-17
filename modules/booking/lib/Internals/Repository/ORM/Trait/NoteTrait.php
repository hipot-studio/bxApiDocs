<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Trait;

use Bitrix\Booking\Internals\Exception\Note\CreateNoteException;
use Bitrix\Booking\Internals\Exception\Note\RemoveNoteException;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Model\Enum\NoteType;
use Bitrix\Booking\Internals\Model\EO_Booking;
use Bitrix\Booking\Internals\Model\EO_Notes;
use Bitrix\Booking\Internals\Model\EO_WaitListItem;
use Bitrix\Booking\Internals\Model\NotesTable;

trait NoteTrait
{
	private function handleNote(
		EO_Booking|EO_WaitListItem $ormEntity,
		string|null $noteDescription,
		int $entityId,
		EntityType $entityType,
		NoteType $noteType,
	): void
	{
		if ($noteDescription === null)
		{
			return;
		}

		$isEmptyNote = $noteDescription === '';

		$note = $this->getNote($ormEntity, $noteType);

		if ($isEmptyNote && $note->getId())
		{
			$noteDeleteResult = $note->delete();
			if (!$noteDeleteResult->isSuccess())
			{
				throw new RemoveNoteException($noteDeleteResult->getErrors()[0]->getMessage());
			}

			return;
		}

		if ($isEmptyNote)
		{
			return;
		}

		$note->setDescription($noteDescription);
		$note->setEntityType($entityType->value);
		$note->setNoteType($noteType->value);
		$note->setEntityId($entityId);
		$noteSaveResult = $note->save();

		if (!$noteSaveResult->isSuccess())
		{
			throw new CreateNoteException($noteSaveResult->getErrors()[0]->getMessage());
		}
	}

	private function getNote(EO_Booking|EO_WaitListItem $ormEntity, NoteType $noteType): EO_Notes
	{
		if ($noteType === NoteType::Manager)
		{
			return $ormEntity->fillNote() ?? NotesTable::createObject();
		}

		if ($noteType === NoteType::Client)
		{
			return $ormEntity->fillClientNote() ?? NotesTable::createObject();
		}

		throw new CreateNoteException('Unknown note type');
	}
}
