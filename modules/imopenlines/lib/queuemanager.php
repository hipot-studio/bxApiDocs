<?php
namespace Bitrix\ImOpenLines;

use Bitrix\Main\Loader;
use Bitrix\Im\User;
use Bitrix\Im\V2\Entity\User\User as UserV2;
use Bitrix\ImOpenLines\Model\QueueTable;
use Bitrix\ImOpenLines\Model\ConfigQueueTable;
use Bitrix\ImOpenLines\Integrations\HumanResources\StructureService;

/**
 * Class QueueManager
 * @package Bitrix\ImOpenLines
 */
class QueueManager
{
	private ?BasicError $error = null;
	private ?int $lineId = null;

	/** @var array<int, int[]> operators of the departments of the queue, read once per save */
	private array $departmentUsers = [];

	/** @var QueueManager[] */
	protected static $instance = [];



	public const EVENT_QUEUE_OPERATORS_ADD = 'OnQueueOperatorsAdd';
	public const EVENT_QUEUE_OPERATORS_DELETE = 'OnQueueOperatorsDelete';
	public const EVENT_QUEUE_OPERATORS_CHANGE = 'OnQueueOperatorsChange';

	protected const validQueueTypeField = [
		'user',
		'department'
	];

	/**
	 * QueueManager constructor.
	 *
	 * @param $lineId
	 */
	public function __construct($lineId)
	{
		$this->error = new BasicError(null, '', '');
		$this->lineId = (int)$lineId;
		Loader::includeModule('im');
	}

	/**
	 * @param int $lineId
	 * @return self
	 */
	public static function getInstance($lineId): self
	{
		if (
			empty(self::$instance[$lineId])
			|| !(self::$instance[$lineId] instanceof self)
		)
		{
			self::$instance[$lineId] = new self($lineId);
		}

		return self::$instance[$lineId];
	}

	/**
	 * @param $fields
	 * @return bool
	 */
	public static function validateQueueFields($fields): bool
	{
		$result = true;

		foreach ($fields as $fieldsEntity)
		{
			if (!(
				!empty($fieldsEntity['ENTITY_ID']) &&
				is_numeric($fieldsEntity['ENTITY_ID']) &&
				$fieldsEntity['ENTITY_ID'] > 0 &&
				self::validateQueueTypeField($fieldsEntity['ENTITY_TYPE'])
			))
			{
				$result = false;
				break;
			}
		}

		return $result;
	}

	/**
	 * @param $fields
	 * @return bool
	 */
	public static function isEmptyQueueFields($fields): bool
	{
		$result = true;

		if (
			!empty($fields)
			&& is_array($fields)
		)
		{
			foreach ($fields as $fieldsEntity)
			{
				if (
					$result === true
					&& !empty($fieldsEntity['ENTITY_ID'])
				)
				{
					if ($fieldsEntity['ENTITY_TYPE'] === 'user')
					{
						$result = false;
						break;
					}
					elseif ($fieldsEntity['ENTITY_TYPE'] === 'department')
					{
						$usersDepartment = StructureService::getInstance()->getDepartmentUserIds($fieldsEntity['ENTITY_ID']);
						if (!empty($usersDepartment))
						{
							$result = false;
							break;
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * @param $type
	 * @return bool
	 */
	public static function validateQueueTypeField($type): bool
	{
		return
			!empty($type)
			&& is_string($type)
			&& in_array($type, self::validQueueTypeField, true)
		;
	}

	/**
	 * @param $configId
	 * @param $fields
	 * @param $numQueue
	 * @return array
	 */
	protected static function getDataAddConfigQueue($configId, $fields, $numQueue): array
	{
		return [
			'SORT' => $numQueue,
			'CONFIG_ID' => $configId,
			'ENTITY_ID' => $fields['ENTITY_ID'],
			'ENTITY_TYPE' => $fields['ENTITY_TYPE'],
		];
	}

	/**
	 * @param $fields
	 * @param $currentFields
	 * @param $numQueue
	 * @return array
	 */
	protected static function getDataUpdateConfigQueue($fields, $currentFields, $numQueue): array
	{
		$result = [];

		$updateFields = [
			'SORT' => $numQueue,
			'ENTITY_ID' => $fields['ENTITY_ID'],
			'ENTITY_TYPE' => $fields['ENTITY_TYPE'],
		];

		$currentFields = [
			'SORT' => $currentFields['SORT'],
			'ENTITY_ID' => $currentFields['ENTITY_ID'],
			'ENTITY_TYPE' => $currentFields['ENTITY_TYPE'],
		];

		if (!empty(array_diff_assoc($updateFields, $currentFields)))
		{
			$result = $updateFields;
		}

		return $result;
	}

	/**
	 * @param $configId
	 * @param $userId
	 * @param $usersFields
	 * @param $numUser
	 * @param int $departmentId
	 * @return array
	 */
	protected static function getDataAddUser($configId, $userId, $usersFields, $numUser, $departmentId = 0): array
	{
		$result = [
			'SORT' => $numUser,
			'CONFIG_ID' => $configId,
			'USER_ID' => $userId,
			'DEPARTMENT_ID' => $departmentId
		];

		if ($usersFields !== false)
		{
			if (!empty($usersFields[$userId]['USER_NAME']))
			{
				$result['USER_NAME'] = $usersFields[$userId]['USER_NAME'];
			}
			if (!empty($usersFields[$userId]['USER_WORK_POSITION']))
			{
				$result['USER_WORK_POSITION'] = $usersFields[$userId]['USER_WORK_POSITION'];
			}
			if (!empty($usersFields[$userId]['USER_AVATAR']))
			{
				$result['USER_AVATAR'] = $usersFields[$userId]['USER_AVATAR'];
			}
			if (!empty($usersFields[$userId]['USER_AVATAR_ID']))
			{
				$result['USER_AVATAR_ID'] = $usersFields[$userId]['USER_AVATAR_ID'];
			}
		}

		return $result;
	}

	/**
	 * @param $userId
	 * @param $currentUser
	 * @param $usersFields
	 * @param $numUser
	 * @param int $departmentId
	 * @return array
	 */
	protected static function getDataUpdateUser($userId, $currentUser, $usersFields, $numUser, $departmentId = 0): array
	{
		$result = [];

		$dataUpdate = [
			'SORT' => $numUser,
			'USER_ID' => $userId,
			'DEPARTMENT_ID' => $departmentId
		];

		if ($usersFields !== false)
		{
			if (!empty($usersFields[$userId]['USER_NAME']))
			{
				$dataUpdate['USER_NAME'] = $usersFields[$userId]['USER_NAME'];
			}
			else
			{
				$dataUpdate['USER_NAME'] = null;
			}

			if (!empty($usersFields[$userId]['USER_WORK_POSITION']))
			{
				$dataUpdate['USER_WORK_POSITION'] = $usersFields[$userId]['USER_WORK_POSITION'];
			}
			else
			{
				$dataUpdate['USER_WORK_POSITION'] = null;
			}
			if (!empty($usersFields[$userId]['USER_AVATAR']))
			{
				$dataUpdate['USER_AVATAR'] = $usersFields[$userId]['USER_AVATAR'];
			}
			else
			{
				$dataUpdate['USER_AVATAR'] = null;
			}
			if (!empty($usersFields[$userId]['USER_AVATAR_ID']))
			{
				$dataUpdate['USER_AVATAR_ID'] = $usersFields[$userId]['USER_AVATAR_ID'];
			}
			else
			{
				$dataUpdate['USER_AVATAR_ID'] = 0;
			}
			if (
				!empty($currentUser['USER_AVATAR_ID'])
				&& (
					empty($dataUpdate['USER_AVATAR_ID'])
					|| $dataUpdate['USER_AVATAR_ID'] != $currentUser['USER_AVATAR_ID']
				)
			)
			{
				\CFile::Delete($currentUser['USER_AVATAR_ID']);
			}
		}

		if ($usersFields === false)
		{
			$currentUser = [
				'SORT' => $currentUser['SORT'],
				'USER_ID' => $currentUser['USER_ID'],
				'DEPARTMENT_ID' => $currentUser['DEPARTMENT_ID'],
			];
		}
		else
		{
			$currentUser = [
				'SORT' => $currentUser['SORT'],
				'USER_ID' => $currentUser['USER_ID'],
				'DEPARTMENT_ID' => $currentUser['DEPARTMENT_ID'],
				'USER_NAME' => $currentUser['USER_NAME'],
				'USER_WORK_POSITION' => $currentUser['USER_WORK_POSITION'],
				'USER_AVATAR' => $currentUser['USER_AVATAR'],
				'USER_AVATAR_ID' => $currentUser['USER_AVATAR_ID'],
			];
		}

		if (!empty(array_diff_assoc($dataUpdate, $currentUser)))
		{
			$result = $dataUpdate;
		}

		return $result;
	}

	/**
	 * @param $userId
	 * @return bool
	 */
	public static function isValidUser($userId): bool
	{
		return
			$userId > 0
			&&
			(
				!Loader::includeModule('im') ||
				(
					!UserV2::getInstance((int)$userId)->isExtranet()
					&& UserV2::getInstance((int)$userId)->isActive()
				)
			)
		;
	}

	/**
	 * @param $queue
	 * @return array
	 */
	public static function getUsersFromQueue($queue): array
	{
		$result = [];

		if (
			!empty($queue)
			&& is_array($queue)
		)
		{
			foreach ($queue as $entity)
			{
				if (self::validateQueueTypeField($entity['type']))
				{
					if (
						(string)$entity['type'] === 'user'
						&& empty($result[$entity['id']])
						&& self::isValidUser($entity['id'])
					)
					{
						$result[$entity['id']] = [
							'id' => $entity['id'],
							'type' => 'user',
							'department' => '0'
						];
					}
					elseif ((string)$entity['type'] === 'department')
					{
						$usersDepartment = StructureService::getInstance()->getDepartmentUserIds($entity['id']);
						foreach ($usersDepartment as $userId)
						{
							if (
								empty($result[$userId])
								&& self::isValidUser($userId)
							)
							{
								$result[$userId] = [
									'id' => $userId,
									'type' => 'user',
									'department' => $entity['id']
								];
							}
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * @param $queue
	 * @return array
	 */
	public static function getUserListFromQueue($queue): array
	{
		$result = [];
		$users = self::getUsersFromQueue($queue);

		foreach ($users as $user)
		{
			$result[] = $user['id'];
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected function getDefaultUsers(): array
	{
		$result = [];
		$userId = User::getInstance()->getId();

		if (!self::isValidUser($userId))
		{
			$userId = 0;
		}

		if (!empty($userId))
		{
			$data = [
				'CONFIG_ID' => $this->lineId,
				'USER_ID' => $userId,
			];
			$userFields = $this->getUserFields($userId);

			if (!empty($userFields))
			{
				$data = array_merge($data, $userFields);
				$data['USER_AVATAR'] = '';
			}

			$result[] = $data;
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public function getConfigQueue()
	{
		$result = [];

		$raw = ConfigQueueTable::getList([
			'select' => [
				'ID',
				'SORT',
				'CONFIG_ID',
				'ENTITY_ID',
				'ENTITY_TYPE'
			],
			'filter' => ['=CONFIG_ID' => $this->lineId],
			'order' => [
				'SORT' => 'ASC',
				'ID' => 'ASC'
			],
		]);

		while ($row = $raw->fetch())
		{
			$result[$row['ID']] = $row;
		}

		//TODO: For migration
		if (count($result) === 0)
		{
			$raw = Queue::getList([
				'select' => [
					'SORT',
					'USER_ID'
				],
				'filter' => [
					'=CONFIG_ID' => $this->lineId,
					'=USER.ACTIVE' => 'Y'
				],
				'order' => [
					'SORT' => 'ASC',
					'ID' => 'ASC'
				],
			]);

			while ($row = $raw->fetch())
			{
				$fieldsAdd = [
					'SORT' => $row['SORT'],
					'CONFIG_ID' => $this->lineId,
					'ENTITY_ID' => $row['USER_ID'],
					'ENTITY_TYPE' => 'user',
				];

				$resultAdd = ConfigQueueTable::add($fieldsAdd);
				if ($resultAdd->isSuccess())
				{
					$idAdd = $resultAdd->getId();
					$result[$idAdd] = array_merge(['ID' => $idAdd], $fieldsAdd);
				}
			}
		}

		return $result;
	}

	/**
	 * @param $items
	 * @return bool
	 */
	public function deleteItemsConfigQueue($items)
	{
		$result = false;

		$currentItems = $this->getConfigQueue();

		$resultItems = [];
		foreach ($currentItems as $currentItem)
		{
			$delete = false;
			if (!empty($items))
			{
				foreach ($items as $id=>$item)
				{
					if (
						(int)$currentItem['ENTITY_ID'] === (int)$item['ENTITY_ID']
						&& $currentItem['ENTITY_TYPE'] === $item['ENTITY_TYPE']
					)
					{
						$delete = true;
						unset($items[$id]);
						$result = true;
					}
				}
			}
			if ($delete === false)
			{
				$resultItems[] = [
					'ENTITY_ID' => $currentItem['ENTITY_ID'],
					'ENTITY_TYPE' => $currentItem['ENTITY_TYPE'],
				];
			}
		}

		$this->update($resultItems);

		return $result;
	}

	/**
	 * @return bool
	 */
	public function refresh()
	{
		$currentItems = $this->getConfigQueue();
		$resultItems = [];
		foreach ($currentItems as $currentItem)
		{
			$resultItems[] = [
				'ENTITY_ID' => $currentItem['ENTITY_ID'],
				'ENTITY_TYPE' => $currentItem['ENTITY_TYPE'],
			];
		}

		$this->update($resultItems);

		return true;
	}

	/**
	 * @param $fields
	 * @param array|false $usersFields
	 * @return bool
	 */
	public function compatibleUpdate($fields, $usersFields = false)
	{
		foreach ($fields as $cell=>$field)
		{
			if (!is_array($field))
			{
				$fields[$cell] = [
					'ENTITY_TYPE' => 'user',
					'ENTITY_ID' => $field
				];
			}
		}

		$resultUpdate = $this->update($fields, $usersFields);

		return $resultUpdate->isSuccess();
	}

	/**
	 * @param $fields
	 * @param array|false $usersFields
	 * @return Result
	 */
	public function update($fields, $usersFields = false): Result
	{
		$claimsOfEarlierSaves = QueueAvatar::claimsOfEarlierSaves();

		try
		{
			return $this->updateQueue($fields, $usersFields, $claimsOfEarlierSaves);
		}
		catch (\Throwable $exception)
		{
			// no row of the queue is written, so an upload taken over for it goes back to the editor
			QueueAvatar::releaseUnwrittenClaims();

			throw $exception;
		}
	}

	/**
	 * @param array<int, true> $claimsOfEarlierSaves
	 */
	private function updateQueue($fields, $usersFields, array $claimsOfEarlierSaves): Result
	{
		$result = new Result();
		$this->departmentUsers = [];

		foreach ($fields as $cell => $fieldsEntity)
		{
			if (
				$fieldsEntity['ENTITY_TYPE'] === 'user'
				&& !self::isValidUser($fieldsEntity['ENTITY_ID'])
			)
			{
				unset($fields[$cell], $usersFields[$fieldsEntity['ENTITY_ID']]);
			}
		}

		if (self::validateQueueFields($fields))
		{
			$queueUsersBefore = [];
			$queueUsersAfter = [];

			$addUsers = [];
			$updateUsers = [];
			$deleteUsers = [];

			$addQueue = [];
			$updateQueue = [];
			$deleteQueue = [];

			$avatarsOfUnwrittenRows = [];

			$usersRaw = QueueTable::getList([
				'filter' => [
					'=CONFIG_ID' => $this->lineId
				],
				'order' => [
					'SORT' => 'ASC',
					'ID' => 'ASC'
				]
			]);
			while ($user = $usersRaw->fetch())
			{
				$currentUsers[$user['USER_ID']] = $user;
				$queueUsersBefore[] = $user['USER_ID'];
				$deleteUsers[$user['USER_ID']] = $user;
			}

			if (is_array($usersFields))
			{
				$usersFields = self::keyUsersFieldsByOperator($usersFields);

				$usersFields = $this->sanitizeUsersAvatars(
					$usersFields,
					$currentUsers ?? [],
					$this->getUserIdsToWrite($fields)
				);
			}

			$configQueueRaw = ConfigQueueTable::getList([
				'filter' => [
					'=CONFIG_ID' => $this->lineId
				],
				'order' => [
					'SORT' => 'ASC',
					'ID' => 'ASC'
				]
			]);
			while ($configQueue = $configQueueRaw->fetch())
			{
				$currentConfigQueue[$configQueue['ENTITY_TYPE']][$configQueue['ENTITY_ID']] = $configQueue;
				$deleteQueue[$configQueue['ENTITY_TYPE']][$configQueue['ENTITY_ID']] = $configQueue;
			}

			$numUser = 0;
			$numQueue = 0;
			$newUsers = [];
			foreach ($fields as $fieldsEntity)
			{
				if (!empty($currentConfigQueue[$fieldsEntity['ENTITY_TYPE']][$fieldsEntity['ENTITY_ID']]))
				{
					unset($deleteQueue[$fieldsEntity['ENTITY_TYPE']][$fieldsEntity['ENTITY_ID']]);

					$updateFields = self::getDataUpdateConfigQueue($fieldsEntity, $currentConfigQueue[$fieldsEntity['ENTITY_TYPE']][$fieldsEntity['ENTITY_ID']], $numQueue);
					if (!empty($updateFields))
					{
						$updateQueue[$currentConfigQueue[$fieldsEntity['ENTITY_TYPE']][$fieldsEntity['ENTITY_ID']]['ID']] = $updateFields;
					}
				}
				else
				{
					$addQueue[] = self::getDataAddConfigQueue($this->lineId, $fieldsEntity, $numQueue);
				}

				if ($fieldsEntity['ENTITY_TYPE'] === 'user')
				{
					if (empty($newUsers[$fieldsEntity['ENTITY_ID']]))
					{
						$queueUsersAfter[] = $fieldsEntity['ENTITY_ID'];

						if (!empty($currentUsers[$fieldsEntity['ENTITY_ID']]))
						{
							unset($deleteUsers[$fieldsEntity['ENTITY_ID']]);

							$updateFields = self::getDataUpdateUser($fieldsEntity['ENTITY_ID'], $currentUsers[$fieldsEntity['ENTITY_ID']], $usersFields, $numUser);

							if (!empty($updateFields))
							{
								$updateUsers[$currentUsers[$fieldsEntity['ENTITY_ID']]['ID']] = $updateFields;
							}
						}
						else
						{
							// a queue naming the operator as "01" replaces the row of the operator 1 instead of
							// updating it, so this is the branch such a name reaches: the fields of it are keyed
							// by the id as an int, and the row must be written from the very fields that were checked
							$addUsers[] = self::getDataAddUser($this->lineId, (int)$fieldsEntity['ENTITY_ID'], $usersFields, $numUser);
						}
					}

					$newUsers[$fieldsEntity['ENTITY_ID']] = true;
					$numUser++;
				}
				elseif ($fieldsEntity['ENTITY_TYPE'] === 'department')
				{
					$usersDepartment = $this->getDepartmentUsers($fieldsEntity['ENTITY_ID']);
					foreach ($usersDepartment as $userId)
					{
						if (self::isValidUser($userId))
						{
							if (empty($newUsers[$userId]))
							{
								$queueUsersAfter[] = $userId;

								if (!empty($currentUsers[$userId]))
								{
									unset($deleteUsers[$userId]);

									$updateFields = self::getDataUpdateUser($userId, $currentUsers[$userId], $usersFields, $numUser, $fieldsEntity['ENTITY_ID']);

									if (!empty($updateFields))
									{
										$updateUsers[$currentUsers[$userId]['ID']] = $updateFields;
									}
								}
								else
								{
									$addUsers[] = self::getDataAddUser($this->lineId, $userId, $usersFields, $numUser, $fieldsEntity['ENTITY_ID']);
								}
							}

							$newUsers[$userId] = true;
							$numUser++;
						}
					}
				}

				$numQueue++;
			}

			if (empty($fields))
			{
				$addUsers = $this->getDefaultUsers();

				if (!empty($addUsers))
				{
					foreach ($addUsers as $addUser)
					{
						$addQueue[] = self::getDataAddConfigQueue($this->lineId, ['ENTITY_TYPE' => 'user', 'ENTITY_ID' => $addUser['USER_ID']], $numQueue);
						$numQueue++;
					}
				}
			}

			if (!empty($deleteQueue))
			{
				foreach ($deleteQueue as $typeEntity)
				{
					if (!empty($typeEntity))
					{
						foreach ($typeEntity as $entity)
						{
							ConfigQueueTable::delete($entity['ID']);
						}
						$result->setResult(true);
					}
				}
			}
			if (!empty($updateQueue))
			{
				foreach ($updateQueue as $id => $queue)
				{
					ConfigQueueTable::update($id, $queue);
				}
				$result->setResult(true);
			}
			if (!empty($addQueue))
			{
				foreach ($addQueue as $queue)
				{
					ConfigQueueTable::add($queue);
				}
				$result->setResult(true);
			}

			if (!empty($deleteUsers))
			{
				foreach ($deleteUsers as $user)
				{
					QueueTable::delete($user['ID']);
				}
				$result->setResult(true);
			}
			if (!empty($updateUsers))
			{
				foreach ($updateUsers as $id => $user)
				{
					if (!QueueTable::update($id, $user)->isSuccess())
					{
						$avatarsOfUnwrittenRows[] = (int)($user['USER_AVATAR_ID'] ?? 0);
					}
				}
				$result->setResult(true);
			}
			if (!empty($addUsers))
			{
				foreach ($addUsers as $user)
				{
					if (!QueueTable::add($user)->isSuccess())
					{
						$avatarsOfUnwrittenRows[] = (int)($user['USER_AVATAR_ID'] ?? 0);
					}
				}
				$result->setResult(true);
			}

			// a row refused by the orm keeps the avatar it had, so the upload taken over for it is stored
			// nowhere: left with the mark of the line it would be neither deletable from the form nor
			// bindable again. The rest of the save stands, so the files go back one by one and only
			// while no written row points to them - a photo given to several operators is held by the
			// rows that did get written
			QueueAvatar::releaseClaims($avatarsOfUnwrittenRows);

			$this->sendQueueChangeEvents($queueUsersBefore, $queueUsersAfter);
		}
		else
		{
			// no row of the queue is written here; the avatars of the rows are checked further down,
			// so what goes back is an upload taken over earlier within this request
			QueueAvatar::releaseUnwrittenClaims();

			$result->addError(new Error('Invalid fields describing queue entities were passed', 'IMOL_ERROR_INCORRECT_FIELDS', __METHOD__, $fields));
		}

		if ($result->isSuccess())
		{
			// the rows are written, so the uploads of their avatars are not this request's to give back
			QueueAvatar::keepWrittenClaims($claimsOfEarlierSaves);
		}

		return $result;
	}

	/**
	 * The operators whose rows this save writes: the entities of the queue of the type user and the
	 * members of its departments. Only the fields of these operators are read further down, so an
	 * upload taken over for any other operator would be stored nowhere and would stay marked as one
	 * of the line - refused by both rules and undeletable from the form of the line.
	 *
	 * A queue of no entities at all writes the default operators instead and reads no fields, so
	 * nothing is taken over for it.
	 *
	 * @return array<int, true>
	 */
	private function getUserIdsToWrite(array $fields): array
	{
		$userIds = [];

		foreach ($fields as $fieldsEntity)
		{
			if ($fieldsEntity['ENTITY_TYPE'] === 'user')
			{
				$userIds[(int)$fieldsEntity['ENTITY_ID']] = true;
			}
			elseif ($fieldsEntity['ENTITY_TYPE'] === 'department')
			{
				foreach ($this->getDepartmentUsers($fieldsEntity['ENTITY_ID']) as $userId)
				{
					if (self::isValidUser($userId))
					{
						$userIds[(int)$userId] = true;
					}
				}
			}
		}

		return $userIds;
	}

	/**
	 * The operators of the department, read once per save: the members are needed both before the
	 * rows are written, to tell which avatars this save may take over, and while they are written.
	 * Every save starts with an empty memory of them, so a save that follows a change of the
	 * structure in the same process reads the members anew.
	 *
	 * @return int[]
	 */
	private function getDepartmentUsers($departmentId): array
	{
		$departmentId = (int)$departmentId;

		return $this->departmentUsers[$departmentId] ??= StructureService::getInstance()->getDepartmentUserIds($departmentId);
	}

	/**
	 * The fields of the operators keyed by the id of the operator as an int. The fields come from a
	 * request, so "01" is as possible a key as 1, and a check reading them one way while the write
	 * reads them the other lets an avatar be taken over for a row that never gets it: the file stays
	 * marked as one of the line while nothing points to it. Of two keys of the same operator the
	 * canonical one wins, so both sides resolve the pair 1 and "01" the same way.
	 *
	 * @return array<int, mixed>
	 */
	private static function keyUsersFieldsByOperator(array $usersFields): array
	{
		$fieldsOfOperators = [];

		foreach ($usersFields as $userId => $fields)
		{
			$operatorId = (int)$userId;

			if (isset($fieldsOfOperators[$operatorId]) && (string)$userId !== (string)$operatorId)
			{
				continue;
			}

			$fieldsOfOperators[$operatorId] = $fields;
		}

		return $fieldsOfOperators;
	}

	/**
	 * Keeps in the queue rows only an avatar id the editor of this line may point to. A rejected id
	 * falls back to the avatar the row holds now, so the refusal neither stores the file nor drops
	 * the current avatar of the operator.
	 *
	 * @param array $usersFields
	 * @param array $currentUsers
	 * @param array<int, true> $userIdsToWrite
	 *
	 * @return array
	 */
	private function sanitizeUsersAvatars(array $usersFields, array $currentUsers, array $userIdsToWrite): array
	{
		// shortcut for the branch of QueueAvatar::claimForBinding() about an avatar of a row of this
		// queue: the rows are already read here, so the check of them costs nothing
		$avatarIdsOfLine = [];
		foreach ($currentUsers as $currentUser)
		{
			$avatarIdsOfLine[(int)$currentUser['USER_AVATAR_ID']] = true;
		}

		foreach ($usersFields as $userId => $fields)
		{
			if (
				!isset($userIdsToWrite[$userId])
				|| !is_array($fields)
				|| !isset($fields['USER_AVATAR_ID'])
			)
			{
				continue;
			}

			$avatarId = is_scalar($fields['USER_AVATAR_ID']) ? (int)$fields['USER_AVATAR_ID'] : 0;

			if (
				$avatarId > 0
				&& !isset($avatarIdsOfLine[$avatarId])
				&& !QueueAvatar::claimForBinding($avatarId, $this->lineId)
			)
			{
				$currentRow = $currentUsers[$userId] ?? [];
				$avatarId = (int)($currentRow['USER_AVATAR_ID'] ?? 0);
				$usersFields[$userId]['USER_AVATAR'] = $avatarId > 0
					? (string)($currentRow['USER_AVATAR'] ?? '')
					: ''
				;
			}

			$usersFields[$userId]['USER_AVATAR_ID'] = $avatarId;
		}

		return $usersFields;
	}

	/**
	 * Return system user fields values for queue fields
	 *
	 * @param $userId
	 *
	 * @return array
	 */
	private function getUserFields($userId): array
	{
		$fields = [];
		$user = User::getInstance($userId);

		if ((int)$user->getId() === (int)$userId)
		{
			$fields['USER_NAME'] = $user->getFullName();
			$fields['USER_WORK_POSITION'] = $user->getWorkPosition();
			$avatar = $user->getAvatar();

			if (mb_substr($avatar, 0, 1) == '/')
			{
				$avatar = \Bitrix\ImOpenLines\Common::getServerAddress() . $avatar;
			}

			$fields['USER_AVATAR'] = $avatar;
		}

		return $fields;
	}

	/**
	 * @return BasicError|null
	 */
	public function getError():?BasicError
	{
		return $this->error;
	}

	/**
	 * Get diff between old queue and new queue and send queue operators change events
	 *
	 * @param $queueBefore
	 * @param $queueAfter
	 */
	private function sendQueueChangeEvents($queueBefore, $queueAfter): void
	{
		$queueRemoved = array_diff($queueBefore, $queueAfter); //list of removed operators
		$queueAdded = array_diff($queueAfter, $queueBefore); //list of added operators

		if (!empty($queueRemoved))
		{
			$this->sendQueueOperatorsDeleteEvent($queueRemoved);
		}

		if (!empty($queueAdded))
		{
			$this->sendQueueOperatorsAddEvent($queueAdded);
		}

		if (!empty($queueAdded) || !empty($queueRemoved))
		{
			$this->sendQueueOperatorsChangeEvent($queueBefore, $queueAfter);
		}
	}

	/**
	 * Send event with list of added to line queue operators
	 *
	 * @param $operators
	 */
	private function sendQueueOperatorsAddEvent($operators): void
	{
		$eventData = [
			'line' => $this->lineId,
			'operators' => $operators
		];
		$event = new \Bitrix\Main\Event('imopenlines', self::EVENT_QUEUE_OPERATORS_ADD, $eventData);
		$event->send();
	}

	/**
	 * Send event with list of removed from line queue operators
	 *
	 * @param $operators
	 */
	private function sendQueueOperatorsDeleteEvent($operators): void
	{
		$eventData = [
			'line' => $this->lineId,
			'operators' => $operators
		];
		$event = new \Bitrix\Main\Event('imopenlines', self::EVENT_QUEUE_OPERATORS_DELETE, $eventData);
		$event->send();
	}

	/**
	 * Send event with lists of queue operators from changed line.
	 *
	 * @param $queueBefore
	 * @param $queueAfter
	 */
	private function sendQueueOperatorsChangeEvent($queueBefore, $queueAfter): void
	{
		$eventData = [
			'line' => $this->lineId,
			'operators_before' => $queueBefore,
			'operators_after' => $queueAfter
		];

		$event = new \Bitrix\Main\Event('imopenlines', self::EVENT_QUEUE_OPERATORS_CHANGE, $eventData);
		$event->send();
	}

	/**
	 * @deprecated
	 *
	 * @param $users
	 * @param array|false $usersFields
	 * @return bool
	 */
	public function updateUsers($users, $usersFields = false)
	{
		return $this->compatibleUpdate($users, $usersFields);
	}
}