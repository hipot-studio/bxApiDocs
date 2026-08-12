<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Note;

use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Public\Command\ReplaceCollectionPermissionsCommand;
use Bitrix\Socialnetwork\Collab\Integration\Note\Service\BindingService;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;

/**
 * Phase 3 (P3.T1): проекция ролей коллаба в ACL коллекции note (owns ALG-01).
 *
 * Direction B: socialnetwork собирает набор прав по текущему составу коллаба и
 * пишет в note через публичную команду {@see ReplaceCollectionPermissionsCommand}
 * (full-replace).
 *
 * Проекция (имена уровней нормативны):
 *  - view (просмотр) — ВСЕ участники через ГРУППОВОЙ код `SG{collabId}_K`
 *    (одна строка, статично: новых/ушедших участников обслуживает платформа CAccess);
 *  - manage (редактирование) — каждый модератор персональным `U{userId}`;
 *  - moderate (администрирование) — владелец персональным `U{userId}`.
 *
 * Владелец/модераторы — именно U-id: групповой код для администрирования/
 * редактирования не подходит. Поэтому при смене владельца/модераторов нужен
 * пересчёт (U-grant переписывается/снимается); участники роли 'K' покрыты
 * статическим SG_K и пересчёта не требуют.
 *
 * Инвариант moderate держит `U{owner}->moderate`; при отсутствии владельца note
 * отклонит набор (NOTE_COLLECTION_NO_MODERATOR) — см. Q-2.
 */
class PermissionProjectionService
{
	private const NOTE_MODULE_ID = 'note';

	private const LEVEL_MODERATE = 'moderate';
	private const LEVEL_MANAGE = 'manage';
	private const LEVEL_VIEW = 'view';

	public function __construct(
		private readonly BindingService $bindingService,
	)
	{
	}

	/**
	 * Builds the full permission projection from the current collab composition.
	 *
	 * Pure projection over membership: no note access, no DB writes. Roles 'Z'
	 * (join requests) are ignored. Returns the flat permission list expected by
	 * NOTE-PERM-01.
	 *
	 * @return list<array{subjectCode: string, level: string}>
	 */
	public function buildPermissions(int $collabId): array
	{
		if ($collabId <= 0)
		{
			return [];
		}

		$collab = Workgroup::getById($collabId);
		if (!($collab instanceof Workgroup))
		{
			return [];
		}

		return $this->projectMembers($collabId, $collab->getMemberIdsWithRole());
	}

	/**
	 * Pure projection of a role map into a permission list (ALG-01).
	 *
	 * Чистая функция (без DB/note) — unit-тестируема. Схема:
	 *  - view  — все участники через ГРУППОВОЙ код SG{collabId}_K (одна строка,
	 *            статично; новые/ушедшие участники обслуживаются платформой CAccess);
	 *  - manage (редактирование) — каждый модератор персональным U{userId};
	 *  - moderate (администрирование) — владелец персональным U{userId}.
	 *
	 * Владелец/модераторы — именно U-id (групповой код для них не подходит).
	 * Роль 'K' (участник/коллабер) отдельного grant не получает — покрыта SG_K.
	 * Заявки 'Z' и прочее игнорируются.
	 *
	 * @param array<int, string> $members userId => 'A'|'E'|'K'|'Z'
	 *
	 * @return list<array{subjectCode: string, level: string}>
	 */
	public function projectMembers(int $collabId, array $members): array
	{
		// view — все участники через групповой код.
		$permissions = [
			['subjectCode' => 'SG' . $collabId . '_K', 'level' => self::LEVEL_VIEW],
		];

		// Администрирование/редактирование — персонально по U{userId}.
		foreach ($members as $userId => $role)
		{
			$userId = (int)$userId;
			if ($userId <= 0)
			{
				continue;
			}

			$level = match ($role) {
				UserToGroupTable::ROLE_OWNER => self::LEVEL_MODERATE,     // владелец
				UserToGroupTable::ROLE_MODERATOR => self::LEVEL_MANAGE,   // модератор
				default => null, // участник (K) -> покрыт SG_K->view; заявки (Z) и пр. — игнор
			};
			if ($level === null)
			{
				continue;
			}

			$permissions[] = ['subjectCode' => 'U' . $userId, 'level' => $level];
		}

		return $permissions;
	}

	/**
	 * Пересчёт ACL коллекции note по текущему составу коллаба с сохранением
	 * ручных выдач (merge, а не слепой full-replace).
	 *
	 * Проекция ролей владеет только персональными U-grant'ами владельца/модераторов
	 * и групповым `SG{collabId}_K`. Всё остальное в ACL коллекции (департаменты,
	 * другие группы, выданные вручную через попап прав Базы знаний персональные
	 * права рядовым участникам/внешним пользователям) — «чужие» строки, и пересчёт
	 * их СОХРАНЯЕТ. Иначе изменение состава модераторов затирало бы ручные права
	 * не-модераторов (bug 249950).
	 *
	 * $privilegedBeforeIds — пользователи, бывшие владельцем/модератором ДО
	 * изменения (снимок из хендлера). Те из них, кто больше не привилегирован
	 * (понижение модератора, смена владельца, удаление привилегированного
	 * участника), теряют персональный U-grant — их и только их проекция снимает.
	 * Пустой список безопасен: тогда пересчёт лишь актуализирует роли и ничего
	 * не снимает.
	 *
	 * Guarded cross-module: без модуля note — no-op (успех). No-op (успех), если
	 * у коллаба нет привязанной коллекции. Возвращает Result: вызывающий
	 * (очередь {@see Async\CollabNoteAclReceiver}) на неуспех делает retry.
	 * Идемпотентен: повторный вызов при том же состоянии даёт тот же ACL.
	 *
	 * @param list<int> $privilegedBeforeIds
	 */
	public function recalculate(int $collabId, array $privilegedBeforeIds = []): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if ($collabId <= 0 || !Loader::includeModule(self::NOTE_MODULE_ID))
		{
			return $result; // нечего/нечем делать — это не ошибка
		}

		try
		{
			$collectionId = $this->bindingService->findCollectionIdByCollab($collabId);
			if ($collectionId === null)
			{
				return $result; // нет привязки — no-op
			}

			$collab = Workgroup::getById($collabId);
			if (!($collab instanceof Workgroup))
			{
				return $result;
			}

			$members = $collab->getMemberIdsWithRole();
			$projection = $this->projectMembers($collabId, $members);
			if ($projection === [])
			{
				return $result;
			}

			$permissions = $this->mergeWithExisting(
				$collectionId,
				$projection,
				$this->resolveRevokedSubjectCodes($members, $privilegedBeforeIds),
			);

			return (new ReplaceCollectionPermissionsCommand(
				$collectionId,
				$permissions,
				$this->resolveActorId($collabId),
			))->run();
		}
		catch (\Throwable $e)
		{
			$result->addError(new \Bitrix\Main\Error($e->getMessage()));

			return $result;
		}
	}

	/**
	 * Строит итоговый набор прав: текущий ACL коллекции, из которого сняты
	 * персональные grant'ы утративших привилегию, поверх наложена проекция ролей.
	 *
	 * Проекция перебивает совпадающий subjectCode (роль — источник истины для
	 * своих строк). Policy-строка `*` командой note не передаётся и остаётся
	 * `none` (участники видят коллекцию через `SG{collabId}_K`).
	 *
	 * @param list<array{subjectCode: string, level: string}> $projection
	 * @param list<string> $revokedSubjectCodes
	 *
	 * @return list<array{subjectCode: string, level: string}>
	 */
	private function mergeWithExisting(int $collectionId, array $projection, array $revokedSubjectCodes): array
	{
		return $this->mergePermissions(
			CollectionAccessService::getCollectionPermissions($collectionId),
			$projection,
			$revokedSubjectCodes,
		);
	}

	/**
	 * Чистая функция merge (без DB/note) — unit-тестируема. Из текущего ACL
	 * убираются снятые персональные коды, поверх накладывается проекция ролей
	 * (роль перебивает совпадающий subjectCode — источник истины для своих строк).
	 *
	 * @param list<array{subjectCode: string, level: string}> $existing текущий ACL коллекции (без policy `*`)
	 * @param list<array{subjectCode: string, level: string}> $projection
	 * @param list<string> $revokedSubjectCodes
	 *
	 * @return list<array{subjectCode: string, level: string}>
	 */
	public function mergePermissions(array $existing, array $projection, array $revokedSubjectCodes): array
	{
		$merged = [];
		foreach ($existing as $row)
		{
			$code = (string)($row['subjectCode'] ?? '');
			if ($code !== '')
			{
				$merged[$code] = (string)($row['level'] ?? '');
			}
		}

		foreach ($revokedSubjectCodes as $code)
		{
			unset($merged[$code]);
		}

		foreach ($projection as $permission)
		{
			$code = (string)($permission['subjectCode'] ?? '');
			if ($code !== '')
			{
				$merged[$code] = (string)($permission['level'] ?? '');
			}
		}

		$permissions = [];
		foreach ($merged as $code => $level)
		{
			$permissions[] = ['subjectCode' => $code, 'level' => $level];
		}

		return $permissions;
	}

	/**
	 * Персональные коды `U{id}` пользователей, которые были владельцем/модератором
	 * до изменения, но сейчас привилегии не имеют. Именно эти (и только эти)
	 * персональные grant'ы снимает проекция; ручные права остаются нетронутыми.
	 *
	 * Чистая функция (без DB/note) — unit-тестируема.
	 *
	 * @param array<int, string> $members текущий состав: userId => 'A'|'E'|'K'|'Z'
	 * @param list<int> $privilegedBeforeIds
	 *
	 * @return list<string>
	 */
	public function resolveRevokedSubjectCodes(array $members, array $privilegedBeforeIds): array
	{
		$privilegedNow = [];
		foreach ($members as $userId => $role)
		{
			if ($role === UserToGroupTable::ROLE_OWNER || $role === UserToGroupTable::ROLE_MODERATOR)
			{
				$privilegedNow[(int)$userId] = true;
			}
		}

		$revoked = [];
		foreach ($privilegedBeforeIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && !isset($privilegedNow[$userId]))
			{
				$revoked[] = 'U' . $userId;
			}
		}

		return $revoked;
	}

	/**
	 * Actor for the ACL write: collab owner, or system (0) when unavailable.
	 */
	private function resolveActorId(int $collabId): int
	{
		$collab = Workgroup::getById($collabId);
		if ($collab instanceof Workgroup)
		{
			$ownerId = $collab->getOwnerId();
			if ($ownerId > 0)
			{
				return $ownerId;
			}
		}

		return 0;
	}
}
