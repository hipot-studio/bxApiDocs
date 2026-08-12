<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Update;

use Bitrix\Socialnetwork\Collab\Integration\Note\Async\CollabNoteAclRecalcMessage;
use Bitrix\Socialnetwork\Collab\Integration\Note\Service\BindingService;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\Control\Command\UpdateCommand;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Control\Handler\Update\UpdateHandlerInterface;
use Bitrix\Socialnetwork\Integration\HumanResources\AccessCodeConverter;
use Bitrix\Socialnetwork\Item\Workgroup;

/**
 * Phase 3 (P3.T2): пересчёт ACL коллекции note при изменении персональных
 * U-grant'ов коллаба.
 *
 * Прямой триггер в collab update-pipeline (а не подписка на legacy-события
 * socialnetwork): membership-операции коллаба строят CollabUpdateCommand
 * и проходят через CollabService::update -> getUpdateHandlers. Этот хендлер
 * ставится ПОСЛЕДНИМ — к этому моменту состав уже изменён, и мы видим итог.
 *
 * Пересчёт публикуется ТОЛЬКО при изменении персональных U-grant'ов:
 * назначение/снятие модератора, смена владельца, а также полное удаление
 * участника, который был владельцем или модератором (чтобы снять висячий
 * U-grant). Добавление/приглашение рядового участника и его полное удаление
 * пересчёт НЕ запускают — view рядового участника обеспечивается статическим
 * групповым кодом SG{collabId}_K через CAccess (динамически по составу группы).
 *
 * Если команда затронула персональные U-grant'ы и у коллаба есть привязанная
 * коллекция note — публикуем пересчёт в надёжную очередь
 * ({@see CollabNoteAclRecalcMessage}) вместе со снимком владельца/модераторов ДО
 * изменения; сам пересчёт (merge с сохранением ручных прав) делает
 * {@see \Bitrix\Socialnetwork\Collab\Integration\Note\Async\CollabNoteAclReceiver}.
 * Снимок «до» нужен, чтобы снять персональные права ровно у тех, кто утратил
 * привилегию, и не затереть ручные выдачи не-модераторам (bug 249950). Хендлер
 * не пересчитывает синхронно (очередь даёт ретраи и не блокирует операцию).
 * Группу не меняет.
 */
class UpdateNotePermissionsHandler implements UpdateHandlerInterface
{
	public function update(UpdateCommand $command, Workgroup $entityBefore, Workgroup $entityAfter): HandlerResult
	{
		$result = new HandlerResult();

		if (!$this->isMembershipAffected($command, $entityBefore, $entityAfter))
		{
			return $result;
		}

		try
		{
			$collabId = $command->getId();
			if ($collabId <= 0)
			{
				return $result;
			}

			// Нет привязанной коллекции note — нечего пересчитывать.
			if (Container::getInstance()->get(BindingService::class)->findCollectionIdByCollab($collabId) === null)
			{
				return $result;
			}

			(new CollabNoteAclRecalcMessage($collabId, $this->privilegedMemberIds($entityBefore)))->sendToQueue();
		}
		catch (\Throwable $e)
		{
			// Defensive: публикация пересчёта не должна валить обновление коллаба.
			\CEventLog::Add([
				'SEVERITY' => 'WARNING',
				'AUDIT_TYPE_ID' => 'COLLAB_NOTE_ACL_ENQUEUE_FAILED',
				'MODULE_ID' => 'socialnetwork',
				'ITEM_ID' => $command->getId(),
				'DESCRIPTION' => $e->getMessage(),
			]);
		}

		return $result;
	}

	private function isMembershipAffected(
		UpdateCommand $command,
		Workgroup $entityBefore,
		Workgroup $entityAfter,
	): bool
	{
		return !empty($command->getAddModeratorMembers())
			|| !empty($command->getDeleteModeratorMembers())
			|| $entityBefore->getOwnerId() !== $entityAfter->getOwnerId()
			|| $this->deletesPrivilegedMember($command, $entityBefore);
	}

	/**
	 * Возвращает true, если среди удаляемых участников есть владелец или модератор.
	 * Полное удаление участника-модератора или владельца оставляет висячий
	 * персональный U-grant (manage/moderate) — его нужно снять пересчётом ACL.
	 * Удаление рядового участника пересчёта не требует.
	 *
	 * Fail-safe: если определить привилегированность удаляемых не удалось
	 * (исключение в AccessCodeConverter), возвращает true — безопаснее запустить
	 * пересчёт (снимет персональные U-grant'ы), чем пропустить и оставить
	 * висячий grant. Исключение не пробрасывается в update().
	 */
	private function deletesPrivilegedMember(UpdateCommand $command, Workgroup $entityBefore): bool
	{
		$deleted = $command->getDeleteMembers();
		if (empty($deleted))
		{
			return false;
		}

		try
		{
			$deletedUserIds = (new AccessCodeConverter(...$deleted))->getUserIds();
		}
		catch (\Throwable $e)
		{
			// Fail-safe: не смогли определить привилегированность удаляемых —
			// безопаснее запустить пересчёт (снимет персональные U-grant'ы),
			// чем пропустить и оставить висячий grant. И не роняем update коллаба.
			return true;
		}

		if (empty($deletedUserIds))
		{
			return false;
		}

		$ownerId = $entityBefore->getOwnerId();
		$privileged = $entityBefore->getModeratorMemberIds();
		if ($ownerId > 0)
		{
			$privileged[] = $ownerId;
		}

		return !empty(array_intersect($deletedUserIds, $privileged));
	}

	/**
	 * Снимок владельца и модераторов ДО изменения. Пересчёт по нему снимет
	 * персональные U-grant'ы тех, кто утратил привилегию (понижение модератора,
	 * смена владельца, удаление привилегированного участника), не трогая ручные
	 * права рядовых участников. Реестр гарантированно заполняет MODERATOR_MEMBERS
	 * и OWNER для entityBefore.
	 *
	 * @return list<int>
	 */
	private function privilegedMemberIds(Workgroup $entityBefore): array
	{
		$ids = $entityBefore->getModeratorMemberIds();

		$ownerId = $entityBefore->getOwnerId();
		if ($ownerId > 0)
		{
			$ids[] = $ownerId;
		}

		return array_values(array_unique(array_map('intval', $ids)));
	}
}
