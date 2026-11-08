<?php

/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable */
namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model {
	/**
	 * EO_WorkflowResumeMessage
	 * @see \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getQueueId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setQueueId(\string|\Bitrix\Main\DB\SqlExpression $queueId)
	 * @method bool hasQueueId()
	 * @method bool isQueueIdFilled()
	 * @method bool isQueueIdChanged()
	 * @method \string remindActualQueueId()
	 * @method \string requireQueueId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetQueueId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetQueueId()
	 * @method \string fillQueueId()
	 * @method null|\string getItemId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setItemId(null|\string|\Bitrix\Main\DB\SqlExpression $itemId)
	 * @method bool hasItemId()
	 * @method bool isItemIdFilled()
	 * @method bool isItemIdChanged()
	 * @method null|\string remindActualItemId()
	 * @method null|\string requireItemId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetItemId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetItemId()
	 * @method null|\string fillItemId()
	 * @method \string getClass()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setClass(\string|\Bitrix\Main\DB\SqlExpression $class)
	 * @method bool hasClass()
	 * @method bool isClassFilled()
	 * @method bool isClassChanged()
	 * @method \string remindActualClass()
	 * @method \string requireClass()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetClass()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetClass()
	 * @method \string fillClass()
	 * @method \string getPayload()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setPayload(\string|\Bitrix\Main\DB\SqlExpression $payload)
	 * @method bool hasPayload()
	 * @method bool isPayloadFilled()
	 * @method bool isPayloadChanged()
	 * @method \string remindActualPayload()
	 * @method \string requirePayload()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetPayload()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetPayload()
	 * @method \string fillPayload()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 * @method \int getTtl()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setTtl(\int|\Bitrix\Main\DB\SqlExpression $ttl)
	 * @method bool hasTtl()
	 * @method bool isTtlFilled()
	 * @method bool isTtlChanged()
	 * @method \int remindActualTtl()
	 * @method \int requireTtl()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetTtl()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetTtl()
	 * @method \int fillTtl()
	 * @method \Bitrix\Main\Type\DateTime getAvailableAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setAvailableAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $availableAt)
	 * @method bool hasAvailableAt()
	 * @method bool isAvailableAtFilled()
	 * @method bool isAvailableAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualAvailableAt()
	 * @method \Bitrix\Main\Type\DateTime requireAvailableAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetAvailableAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetAvailableAt()
	 * @method \Bitrix\Main\Type\DateTime fillAvailableAt()
	 * @method \string getStatus()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage setStatus(\string|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \string remindActualStatus()
	 * @method \string requireStatus()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage resetStatus()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unsetStatus()
	 * @method \string fillStatus()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage wakeUp($data)
	 */
	class EO_WorkflowResumeMessage extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model {
	/**
	 * EO_WorkflowResumeMessage_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getQueueIdList()
	 * @method \string[] fillQueueId()
	 * @method null|\string[] getItemIdList()
	 * @method null|\string[] fillItemId()
	 * @method \string[] getClassList()
	 * @method \string[] fillClass()
	 * @method \string[] getPayloadList()
	 * @method \string[] fillPayload()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 * @method \int[] getTtlList()
	 * @method \int[] fillTtl()
	 * @method \Bitrix\Main\Type\DateTime[] getAvailableAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillAvailableAt()
	 * @method \string[] getStatusList()
	 * @method \string[] fillStatus()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection merge(?\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection filter(callable $callback)
	 */
	class EO_WorkflowResumeMessage_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable';
	}
}
namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model {
	/**
	 * @method static EO_WorkflowResumeMessage_Query query()
	 * @method static EO_WorkflowResumeMessage_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowResumeMessage_Result getById($id)
	 * @method static EO_WorkflowResumeMessage_Result getList(array $parameters = [])
	 * @method static EO_WorkflowResumeMessage_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection wakeUpCollection($rows)
	 */
	class WorkflowResumeMessageTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowResumeMessage_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection fetchCollection()
	 */
	class EO_WorkflowResumeMessage_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection fetchCollection()
	 */
	class EO_WorkflowResumeMessage_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowResumeMessage_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable */
namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model {
	/**
	 * EO_WorkflowStartMessage
	 * @see \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getQueueId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setQueueId(\string|\Bitrix\Main\DB\SqlExpression $queueId)
	 * @method bool hasQueueId()
	 * @method bool isQueueIdFilled()
	 * @method bool isQueueIdChanged()
	 * @method \string remindActualQueueId()
	 * @method \string requireQueueId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetQueueId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetQueueId()
	 * @method \string fillQueueId()
	 * @method null|\string getItemId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setItemId(null|\string|\Bitrix\Main\DB\SqlExpression $itemId)
	 * @method bool hasItemId()
	 * @method bool isItemIdFilled()
	 * @method bool isItemIdChanged()
	 * @method null|\string remindActualItemId()
	 * @method null|\string requireItemId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetItemId()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetItemId()
	 * @method null|\string fillItemId()
	 * @method \string getClass()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setClass(\string|\Bitrix\Main\DB\SqlExpression $class)
	 * @method bool hasClass()
	 * @method bool isClassFilled()
	 * @method bool isClassChanged()
	 * @method \string remindActualClass()
	 * @method \string requireClass()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetClass()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetClass()
	 * @method \string fillClass()
	 * @method \string getPayload()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setPayload(\string|\Bitrix\Main\DB\SqlExpression $payload)
	 * @method bool hasPayload()
	 * @method bool isPayloadFilled()
	 * @method bool isPayloadChanged()
	 * @method \string remindActualPayload()
	 * @method \string requirePayload()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetPayload()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetPayload()
	 * @method \string fillPayload()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 * @method \int getTtl()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setTtl(\int|\Bitrix\Main\DB\SqlExpression $ttl)
	 * @method bool hasTtl()
	 * @method bool isTtlFilled()
	 * @method bool isTtlChanged()
	 * @method \int remindActualTtl()
	 * @method \int requireTtl()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetTtl()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetTtl()
	 * @method \int fillTtl()
	 * @method \Bitrix\Main\Type\DateTime getAvailableAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setAvailableAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $availableAt)
	 * @method bool hasAvailableAt()
	 * @method bool isAvailableAtFilled()
	 * @method bool isAvailableAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualAvailableAt()
	 * @method \Bitrix\Main\Type\DateTime requireAvailableAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetAvailableAt()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetAvailableAt()
	 * @method \Bitrix\Main\Type\DateTime fillAvailableAt()
	 * @method \string getStatus()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage setStatus(\string|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \string remindActualStatus()
	 * @method \string requireStatus()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage resetStatus()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unsetStatus()
	 * @method \string fillStatus()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage wakeUp($data)
	 */
	class EO_WorkflowStartMessage extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model {
	/**
	 * EO_WorkflowStartMessage_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getQueueIdList()
	 * @method \string[] fillQueueId()
	 * @method null|\string[] getItemIdList()
	 * @method null|\string[] fillItemId()
	 * @method \string[] getClassList()
	 * @method \string[] fillClass()
	 * @method \string[] getPayloadList()
	 * @method \string[] fillPayload()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 * @method \int[] getTtlList()
	 * @method \int[] fillTtl()
	 * @method \Bitrix\Main\Type\DateTime[] getAvailableAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillAvailableAt()
	 * @method \string[] getStatusList()
	 * @method \string[] fillStatus()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection merge(?\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection filter(callable $callback)
	 */
	class EO_WorkflowStartMessage_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable';
	}
}
namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model {
	/**
	 * @method static EO_WorkflowStartMessage_Query query()
	 * @method static EO_WorkflowStartMessage_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowStartMessage_Result getById($id)
	 * @method static EO_WorkflowStartMessage_Result getList(array $parameters = [])
	 * @method static EO_WorkflowStartMessage_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection wakeUpCollection($rows)
	 */
	class WorkflowStartMessageTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowStartMessage_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection fetchCollection()
	 */
	class EO_WorkflowStartMessage_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection fetchCollection()
	 */
	class EO_WorkflowStartMessage_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowStartMessage_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable */
namespace Bitrix\Bizproc\Internal\Model\LastValues {
	/**
	 * EO_WorkflowLastValues
	 * @see \Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \Bitrix\Main\Type\DateTime getVersionKey()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setVersionKey(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $versionKey)
	 * @method bool hasVersionKey()
	 * @method bool isVersionKeyFilled()
	 * @method bool isVersionKeyChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualVersionKey()
	 * @method \Bitrix\Main\Type\DateTime requireVersionKey()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetVersionKey()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetVersionKey()
	 * @method \Bitrix\Main\Type\DateTime fillVersionKey()
	 * @method null|\string getWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setWorkflowId(null|\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method null|\string remindActualWorkflowId()
	 * @method null|\string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetWorkflowId()
	 * @method null|\string fillWorkflowId()
	 * @method null|\string getModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setModuleId(null|\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method null|\string remindActualModuleId()
	 * @method null|\string requireModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetModuleId()
	 * @method null|\string fillModuleId()
	 * @method null|\string getEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setEntity(null|\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method null|\string remindActualEntity()
	 * @method null|\string requireEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetEntity()
	 * @method null|\string fillEntity()
	 * @method null|\string getDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setDocumentId(null|\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method null|\string remindActualDocumentId()
	 * @method null|\string requireDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetDocumentId()
	 * @method null|\string fillDocumentId()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetStatus()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetStatus()
	 * @method \int fillStatus()
	 * @method \Bitrix\Main\Type\DateTime getCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setCompletedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $completedAt)
	 * @method bool hasCompletedAt()
	 * @method bool isCompletedAtFilled()
	 * @method bool isCompletedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCompletedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetCompletedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCompletedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 * @method \string getValuesData()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues setValuesData(\string|\Bitrix\Main\DB\SqlExpression $valuesData)
	 * @method bool hasValuesData()
	 * @method bool isValuesDataFilled()
	 * @method bool isValuesDataChanged()
	 * @method \string remindActualValuesData()
	 * @method \string requireValuesData()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues resetValuesData()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unsetValuesData()
	 * @method \string fillValuesData()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues wakeUp($data)
	 */
	class EO_WorkflowLastValues extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\LastValues {
	/**
	 * EO_WorkflowLastValues_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getTemplateIdList()
	 * @method \Bitrix\Main\Type\DateTime[] getVersionKeyList()
	 * @method \Bitrix\Main\Type\DateTime[] fillVersionKey()
	 * @method null|\string[] getWorkflowIdList()
	 * @method null|\string[] fillWorkflowId()
	 * @method null|\string[] getModuleIdList()
	 * @method null|\string[] fillModuleId()
	 * @method null|\string[] getEntityList()
	 * @method null|\string[] fillEntity()
	 * @method null|\string[] getDocumentIdList()
	 * @method null|\string[] fillDocumentId()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getCompletedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCompletedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 * @method \string[] getValuesDataList()
	 * @method \string[] fillValuesData()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection merge(?\Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection filter(callable $callback)
	 */
	class EO_WorkflowLastValues_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\LastValues {
	/**
	 * @method static EO_WorkflowLastValues_Query query()
	 * @method static EO_WorkflowLastValues_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowLastValues_Result getById($id)
	 * @method static EO_WorkflowLastValues_Result getList(array $parameters = [])
	 * @method static EO_WorkflowLastValues_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection wakeUpCollection($rows)
	 */
	class WorkflowLastValuesTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowLastValues_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection fetchCollection()
	 */
	class EO_WorkflowLastValues_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection fetchCollection()
	 */
	class EO_WorkflowLastValues_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowLastValues_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StorageFieldTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageField
	 * @see \Bitrix\Bizproc\Internal\Model\StorageFieldTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setStorageId(\int|\Bitrix\Main\DB\SqlExpression $storageId)
	 * @method bool hasStorageId()
	 * @method bool isStorageIdFilled()
	 * @method bool isStorageIdChanged()
	 * @method \int remindActualStorageId()
	 * @method \int requireStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetStorageId()
	 * @method \int fillStorageId()
	 * @method \string getCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetCode()
	 * @method \string fillCode()
	 * @method \int getSort()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetSort()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetSort()
	 * @method \int fillSort()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetDescription()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetDescription()
	 * @method \string fillDescription()
	 * @method \string getType()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetType()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetType()
	 * @method \string fillType()
	 * @method \boolean getMultiple()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setMultiple(\boolean|\Bitrix\Main\DB\SqlExpression $multiple)
	 * @method bool hasMultiple()
	 * @method bool isMultipleFilled()
	 * @method bool isMultipleChanged()
	 * @method \boolean remindActualMultiple()
	 * @method \boolean requireMultiple()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetMultiple()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetMultiple()
	 * @method \boolean fillMultiple()
	 * @method \boolean getMandatory()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setMandatory(\boolean|\Bitrix\Main\DB\SqlExpression $mandatory)
	 * @method bool hasMandatory()
	 * @method bool isMandatoryFilled()
	 * @method bool isMandatoryChanged()
	 * @method \boolean remindActualMandatory()
	 * @method \boolean requireMandatory()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetMandatory()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetMandatory()
	 * @method \boolean fillMandatory()
	 * @method array getSettings()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField setSettings(array|\Bitrix\Main\DB\SqlExpression $settings)
	 * @method bool hasSettings()
	 * @method bool isSettingsFilled()
	 * @method bool isSettingsChanged()
	 * @method array remindActualSettings()
	 * @method array requireSettings()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField resetSettings()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unsetSettings()
	 * @method array fillSettings()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField wakeUp($data)
	 */
	class EO_StorageField extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageFieldTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageFieldTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageField_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getStorageIdList()
	 * @method \int[] fillStorageId()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \boolean[] getMultipleList()
	 * @method \boolean[] fillMultiple()
	 * @method \boolean[] getMandatoryList()
	 * @method \boolean[] fillMandatory()
	 * @method array[] getSettingsList()
	 * @method array[] fillSettings()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_StorageField $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_StorageField $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_StorageField $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection filter(callable $callback)
	 */
	class EO_StorageField_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageFieldTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageFieldTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_StorageField_Query query()
	 * @method static EO_StorageField_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_StorageField_Result getById($id)
	 * @method static EO_StorageField_Result getList(array $parameters = [])
	 * @method static EO_StorageField_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection wakeUpCollection($rows)
	 */
	class StorageFieldTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StorageField_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection fetchCollection()
	 */
	class EO_StorageField_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection fetchCollection()
	 */
	class EO_StorageField_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection wakeUpCollection($rows)
	 */
	class EO_StorageField_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable */
namespace Bitrix\Bizproc\Internal\Model\Trigger {
	/**
	 * EO_TriggerSchedule
	 * @see \Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \string getTriggerName()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setTriggerName(\string|\Bitrix\Main\DB\SqlExpression $triggerName)
	 * @method bool hasTriggerName()
	 * @method bool isTriggerNameFilled()
	 * @method bool isTriggerNameChanged()
	 * @method \string remindActualTriggerName()
	 * @method \string requireTriggerName()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetTriggerName()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetTriggerName()
	 * @method \string fillTriggerName()
	 * @method \string getScheduleType()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setScheduleType(\string|\Bitrix\Main\DB\SqlExpression $scheduleType)
	 * @method bool hasScheduleType()
	 * @method bool isScheduleTypeFilled()
	 * @method bool isScheduleTypeChanged()
	 * @method \string remindActualScheduleType()
	 * @method \string requireScheduleType()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetScheduleType()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetScheduleType()
	 * @method \string fillScheduleType()
	 * @method array getScheduleData()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setScheduleData(array|\Bitrix\Main\DB\SqlExpression $scheduleData)
	 * @method bool hasScheduleData()
	 * @method bool isScheduleDataFilled()
	 * @method bool isScheduleDataChanged()
	 * @method array remindActualScheduleData()
	 * @method array requireScheduleData()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetScheduleData()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetScheduleData()
	 * @method array fillScheduleData()
	 * @method null|\Bitrix\Main\Type\DateTime getNextRunAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setNextRunAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $nextRunAt)
	 * @method bool hasNextRunAt()
	 * @method bool isNextRunAtFilled()
	 * @method bool isNextRunAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualNextRunAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireNextRunAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetNextRunAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetNextRunAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillNextRunAt()
	 * @method null|\Bitrix\Main\Type\DateTime getLastRunAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setLastRunAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $lastRunAt)
	 * @method bool hasLastRunAt()
	 * @method bool isLastRunAtFilled()
	 * @method bool isLastRunAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualLastRunAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireLastRunAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetLastRunAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetLastRunAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillLastRunAt()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule wakeUp($data)
	 */
	class EO_TriggerSchedule extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\Trigger {
	/**
	 * EO_TriggerSchedule_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \string[] getTriggerNameList()
	 * @method \string[] fillTriggerName()
	 * @method \string[] getScheduleTypeList()
	 * @method \string[] fillScheduleType()
	 * @method array[] getScheduleDataList()
	 * @method array[] fillScheduleData()
	 * @method null|\Bitrix\Main\Type\DateTime[] getNextRunAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillNextRunAt()
	 * @method null|\Bitrix\Main\Type\DateTime[] getLastRunAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillLastRunAt()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection merge(?\Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection filter(callable $callback)
	 */
	class EO_TriggerSchedule_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\Trigger {
	/**
	 * @method static EO_TriggerSchedule_Query query()
	 * @method static EO_TriggerSchedule_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TriggerSchedule_Result getById($id)
	 * @method static EO_TriggerSchedule_Result getList(array $parameters = [])
	 * @method static EO_TriggerSchedule_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection wakeUpCollection($rows)
	 */
	class TriggerScheduleTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TriggerSchedule_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection fetchCollection()
	 */
	class EO_TriggerSchedule_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection fetchCollection()
	 */
	class EO_TriggerSchedule_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection wakeUpCollection($rows)
	 */
	class EO_TriggerSchedule_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageRecordField
	 * @see \Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getRecordId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField setRecordId(\int|\Bitrix\Main\DB\SqlExpression $recordId)
	 * @method bool hasRecordId()
	 * @method bool isRecordIdFilled()
	 * @method bool isRecordIdChanged()
	 * @method \int remindActualRecordId()
	 * @method \int requireRecordId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField resetRecordId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField unsetRecordId()
	 * @method \int fillRecordId()
	 * @method \int getFieldId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField setFieldId(\int|\Bitrix\Main\DB\SqlExpression $fieldId)
	 * @method bool hasFieldId()
	 * @method bool isFieldIdFilled()
	 * @method bool isFieldIdChanged()
	 * @method \int remindActualFieldId()
	 * @method \int requireFieldId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField resetFieldId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField unsetFieldId()
	 * @method \int fillFieldId()
	 * @method \string getValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField setValue(\string|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \string remindActualValue()
	 * @method \string requireValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField resetValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField unsetValue()
	 * @method \string fillValue()
	 * @method null|\float getValueNum()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField setValueNum(null|\float|\Bitrix\Main\DB\SqlExpression $valueNum)
	 * @method bool hasValueNum()
	 * @method bool isValueNumFilled()
	 * @method bool isValueNumChanged()
	 * @method null|\float remindActualValueNum()
	 * @method null|\float requireValueNum()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField resetValueNum()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField unsetValueNum()
	 * @method null|\float fillValueNum()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField getField()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField remindActualField()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField requireField()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField setField(\Bitrix\Bizproc\Internal\Model\EO_StorageField $object)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField resetField()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField unsetField()
	 * @method bool hasField()
	 * @method bool isFieldFilled()
	 * @method bool isFieldChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField fillField()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField wakeUp($data)
	 */
	class EO_StorageRecordField extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageRecordField_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getRecordIdList()
	 * @method \int[] fillRecordId()
	 * @method \int[] getFieldIdList()
	 * @method \int[] fillFieldId()
	 * @method \string[] getValueList()
	 * @method \string[] fillValue()
	 * @method null|\float[] getValueNumList()
	 * @method null|\float[] fillValueNum()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField[] getFieldList()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection getFieldCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection fillField()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_StorageRecordField $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_StorageRecordField $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_StorageRecordField $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection filter(callable $callback)
	 */
	class EO_StorageRecordField_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_StorageRecordField_Query query()
	 * @method static EO_StorageRecordField_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_StorageRecordField_Result getById($id)
	 * @method static EO_StorageRecordField_Result getList(array $parameters = [])
	 * @method static EO_StorageRecordField_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection wakeUpCollection($rows)
	 */
	class StorageRecordFieldTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StorageRecordField_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection fetchCollection()
	 */
	class EO_StorageRecordField_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection fetchCollection()
	 */
	class EO_StorageRecordField_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordField_Collection wakeUpCollection($rows)
	 */
	class EO_StorageRecordField_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\RoleRelationTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_RoleRelation
	 * @see \Bitrix\Bizproc\Internal\Model\RoleRelationTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getRoleId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation setRoleId(\int|\Bitrix\Main\DB\SqlExpression $roleId)
	 * @method bool hasRoleId()
	 * @method bool isRoleIdFilled()
	 * @method bool isRoleIdChanged()
	 * @method \int remindActualRoleId()
	 * @method \int requireRoleId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation resetRoleId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation unsetRoleId()
	 * @method \int fillRoleId()
	 * @method \string getRelation()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation setRelation(\string|\Bitrix\Main\DB\SqlExpression $relation)
	 * @method bool hasRelation()
	 * @method bool isRelationFilled()
	 * @method bool isRelationChanged()
	 * @method \string remindActualRelation()
	 * @method \string requireRelation()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation resetRelation()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation unsetRelation()
	 * @method \string fillRelation()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RoleRelation wakeUp($data)
	 */
	class EO_RoleRelation extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\RoleRelationTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\RoleRelationTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_RoleRelation_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getRoleIdList()
	 * @method \int[] fillRoleId()
	 * @method \string[] getRelationList()
	 * @method \string[] fillRelation()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_RoleRelation $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_RoleRelation $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_RoleRelation $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection filter(callable $callback)
	 */
	class EO_RoleRelation_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\RoleRelationTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\RoleRelationTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_RoleRelation_Query query()
	 * @method static EO_RoleRelation_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_RoleRelation_Result getById($id)
	 * @method static EO_RoleRelation_Result getList(array $parameters = [])
	 * @method static EO_RoleRelation_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RoleRelation createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RoleRelation wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection wakeUpCollection($rows)
	 */
	class RoleRelationTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RoleRelation_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection fetchCollection()
	 */
	class EO_RoleRelation_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection fetchCollection()
	 */
	class EO_RoleRelation_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RoleRelation_Collection wakeUpCollection($rows)
	 */
	class EO_RoleRelation_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable */
namespace Bitrix\Bizproc\Internal\Model\TaskArchive {
	/**
	 * EO_TaskArchiveTasks
	 * @see \Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getArchiveId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks setArchiveId(\int|\Bitrix\Main\DB\SqlExpression $archiveId)
	 * @method bool hasArchiveId()
	 * @method bool isArchiveIdFilled()
	 * @method bool isArchiveIdChanged()
	 * @method \int remindActualArchiveId()
	 * @method \int requireArchiveId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks resetArchiveId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks unsetArchiveId()
	 * @method \int fillArchiveId()
	 * @method \int getTaskId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks setTaskId(\int|\Bitrix\Main\DB\SqlExpression $taskId)
	 * @method bool hasTaskId()
	 * @method bool isTaskIdFilled()
	 * @method bool isTaskIdChanged()
	 * @method \int remindActualTaskId()
	 * @method \int requireTaskId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks resetTaskId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks unsetTaskId()
	 * @method \int fillTaskId()
	 * @method \Bitrix\Main\Type\DateTime getCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks setCompletedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $completedAt)
	 * @method bool hasCompletedAt()
	 * @method bool isCompletedAtFilled()
	 * @method bool isCompletedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCompletedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks resetCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks unsetCompletedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive getArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive remindActualArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive requireArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks setArchive(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive $object)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks resetArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks unsetArchive()
	 * @method bool hasArchive()
	 * @method bool isArchiveFilled()
	 * @method bool isArchiveChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive fillArchive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks wakeUp($data)
	 */
	class EO_TaskArchiveTasks extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\TaskArchive {
	/**
	 * EO_TaskArchiveTasks_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getArchiveIdList()
	 * @method \int[] fillArchiveId()
	 * @method \int[] getTaskIdList()
	 * @method \int[] fillTaskId()
	 * @method \Bitrix\Main\Type\DateTime[] getCompletedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCompletedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive[] getArchiveList()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection getArchiveCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection fillArchive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection merge(?\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection filter(callable $callback)
	 */
	class EO_TaskArchiveTasks_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\TaskArchive {
	/**
	 * @method static EO_TaskArchiveTasks_Query query()
	 * @method static EO_TaskArchiveTasks_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TaskArchiveTasks_Result getById($id)
	 * @method static EO_TaskArchiveTasks_Result getList(array $parameters = [])
	 * @method static EO_TaskArchiveTasks_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection wakeUpCollection($rows)
	 */
	class TaskArchiveTasksTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TaskArchiveTasks_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection fetchCollection()
	 */
	class EO_TaskArchiveTasks_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection fetchCollection()
	 */
	class EO_TaskArchiveTasks_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection wakeUpCollection($rows)
	 */
	class EO_TaskArchiveTasks_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable */
namespace Bitrix\Bizproc\Internal\Model\TaskArchive {
	/**
	 * EO_TaskArchive
	 * @see \Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive resetWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \string getTasksData()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive setTasksData(\string|\Bitrix\Main\DB\SqlExpression $tasksData)
	 * @method bool hasTasksData()
	 * @method bool isTasksDataFilled()
	 * @method bool isTasksDataChanged()
	 * @method \string remindActualTasksData()
	 * @method \string requireTasksData()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive resetTasksData()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive unsetTasksData()
	 * @method \string fillTasksData()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks getTasks()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks remindActualTasks()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks requireTasks()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive setTasks(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks $object)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive resetTasks()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive unsetTasks()
	 * @method bool hasTasks()
	 * @method bool isTasksFilled()
	 * @method bool isTasksChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks fillTasks()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive wakeUp($data)
	 */
	class EO_TaskArchive extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\TaskArchive {
	/**
	 * EO_TaskArchive_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \string[] getTasksDataList()
	 * @method \string[] fillTasksData()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks[] getTasksList()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection getTasksCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection fillTasks()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection merge(?\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection filter(callable $callback)
	 */
	class EO_TaskArchive_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\TaskArchive {
	/**
	 * @method static EO_TaskArchive_Query query()
	 * @method static EO_TaskArchive_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TaskArchive_Result getById($id)
	 * @method static EO_TaskArchive_Result getList(array $parameters = [])
	 * @method static EO_TaskArchive_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection wakeUpCollection($rows)
	 */
	class TaskArchiveTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TaskArchive_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection fetchCollection()
	 */
	class EO_TaskArchive_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection fetchCollection()
	 */
	class EO_TaskArchive_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection wakeUpCollection($rows)
	 */
	class EO_TaskArchive_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_WorkflowTemplateFile
	 * @see \Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getFileId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile setFileId(\int|\Bitrix\Main\DB\SqlExpression $fileId)
	 * @method bool hasFileId()
	 * @method bool isFileIdFilled()
	 * @method bool isFileIdChanged()
	 * @method \int remindActualFileId()
	 * @method \int requireFileId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile resetFileId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile unsetFileId()
	 * @method \int fillFileId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile wakeUp($data)
	 */
	class EO_WorkflowTemplateFile extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_WorkflowTemplateFile_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getFileIdList()
	 * @method \int[] fillFileId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateFile_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_WorkflowTemplateFile_Query query()
	 * @method static EO_WorkflowTemplateFile_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateFile_Result getById($id)
	 * @method static EO_WorkflowTemplateFile_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateFile_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateFileTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateFile_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateFile_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateFile_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateFile_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StorageDataViewTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageDataView
	 * @see \Bitrix\Bizproc\Internal\Model\StorageDataViewTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getStorageTypeId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setStorageTypeId(\int|\Bitrix\Main\DB\SqlExpression $storageTypeId)
	 * @method bool hasStorageTypeId()
	 * @method bool isStorageTypeIdFilled()
	 * @method bool isStorageTypeIdChanged()
	 * @method \int remindActualStorageTypeId()
	 * @method \int requireStorageTypeId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetStorageTypeId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetStorageTypeId()
	 * @method \int fillStorageTypeId()
	 * @method \string getDefinition()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setDefinition(\string|\Bitrix\Main\DB\SqlExpression $definition)
	 * @method bool hasDefinition()
	 * @method bool isDefinitionFilled()
	 * @method bool isDefinitionChanged()
	 * @method \string remindActualDefinition()
	 * @method \string requireDefinition()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetDefinition()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetDefinition()
	 * @method \string fillDefinition()
	 * @method \string getStatus()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setStatus(\string|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \string remindActualStatus()
	 * @method \string requireStatus()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetStatus()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetStatus()
	 * @method \string fillStatus()
	 * @method null|\string getErrorText()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setErrorText(null|\string|\Bitrix\Main\DB\SqlExpression $errorText)
	 * @method bool hasErrorText()
	 * @method bool isErrorTextFilled()
	 * @method bool isErrorTextChanged()
	 * @method null|\string remindActualErrorText()
	 * @method null|\string requireErrorText()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetErrorText()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetErrorText()
	 * @method null|\string fillErrorText()
	 * @method \string getDeletionMarks()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setDeletionMarks(\string|\Bitrix\Main\DB\SqlExpression $deletionMarks)
	 * @method bool hasDeletionMarks()
	 * @method bool isDeletionMarksFilled()
	 * @method bool isDeletionMarksChanged()
	 * @method \string remindActualDeletionMarks()
	 * @method \string requireDeletionMarks()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetDeletionMarks()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetDeletionMarks()
	 * @method \string fillDeletionMarks()
	 * @method \Bitrix\Main\Type\DateTime getMaterializedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setMaterializedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $materializedAt)
	 * @method bool hasMaterializedAt()
	 * @method bool isMaterializedAtFilled()
	 * @method bool isMaterializedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualMaterializedAt()
	 * @method \Bitrix\Main\Type\DateTime requireMaterializedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetMaterializedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetMaterializedAt()
	 * @method \Bitrix\Main\Type\DateTime fillMaterializedAt()
	 * @method \int getMaterializedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setMaterializedBy(\int|\Bitrix\Main\DB\SqlExpression $materializedBy)
	 * @method bool hasMaterializedBy()
	 * @method bool isMaterializedByFilled()
	 * @method bool isMaterializedByChanged()
	 * @method \int remindActualMaterializedBy()
	 * @method \int requireMaterializedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetMaterializedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetMaterializedBy()
	 * @method \int fillMaterializedBy()
	 * @method null|\int getOwnerTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setOwnerTemplateId(null|\int|\Bitrix\Main\DB\SqlExpression $ownerTemplateId)
	 * @method bool hasOwnerTemplateId()
	 * @method bool isOwnerTemplateIdFilled()
	 * @method bool isOwnerTemplateIdChanged()
	 * @method null|\int remindActualOwnerTemplateId()
	 * @method null|\int requireOwnerTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetOwnerTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetOwnerTemplateId()
	 * @method null|\int fillOwnerTemplateId()
	 * @method null|\string getOwnerActivityName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setOwnerActivityName(null|\string|\Bitrix\Main\DB\SqlExpression $ownerActivityName)
	 * @method bool hasOwnerActivityName()
	 * @method bool isOwnerActivityNameFilled()
	 * @method bool isOwnerActivityNameChanged()
	 * @method null|\string remindActualOwnerActivityName()
	 * @method null|\string requireOwnerActivityName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetOwnerActivityName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetOwnerActivityName()
	 * @method null|\string fillOwnerActivityName()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \int getUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setUpdatedBy(\int|\Bitrix\Main\DB\SqlExpression $updatedBy)
	 * @method bool hasUpdatedBy()
	 * @method bool isUpdatedByFilled()
	 * @method bool isUpdatedByChanged()
	 * @method \int remindActualUpdatedBy()
	 * @method \int requireUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetUpdatedBy()
	 * @method \int fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setCreatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdTime)
	 * @method bool hasCreatedTime()
	 * @method bool isCreatedTimeFilled()
	 * @method bool isCreatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView setUpdatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedTime)
	 * @method bool hasUpdatedTime()
	 * @method bool isUpdatedTimeFilled()
	 * @method bool isUpdatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView resetUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unsetUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedTime()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageDataView wakeUp($data)
	 */
	class EO_StorageDataView extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageDataViewTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageDataViewTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageDataView_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getStorageTypeIdList()
	 * @method \int[] fillStorageTypeId()
	 * @method \string[] getDefinitionList()
	 * @method \string[] fillDefinition()
	 * @method \string[] getStatusList()
	 * @method \string[] fillStatus()
	 * @method null|\string[] getErrorTextList()
	 * @method null|\string[] fillErrorText()
	 * @method \string[] getDeletionMarksList()
	 * @method \string[] fillDeletionMarks()
	 * @method \Bitrix\Main\Type\DateTime[] getMaterializedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillMaterializedAt()
	 * @method \int[] getMaterializedByList()
	 * @method \int[] fillMaterializedBy()
	 * @method null|\int[] getOwnerTemplateIdList()
	 * @method null|\int[] fillOwnerTemplateId()
	 * @method null|\string[] getOwnerActivityNameList()
	 * @method null|\string[] fillOwnerActivityName()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \int[] getUpdatedByList()
	 * @method \int[] fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedTime()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_StorageDataView $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_StorageDataView $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_StorageDataView $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection filter(callable $callback)
	 */
	class EO_StorageDataView_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageDataViewTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageDataViewTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_StorageDataView_Query query()
	 * @method static EO_StorageDataView_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_StorageDataView_Result getById($id)
	 * @method static EO_StorageDataView_Result getList(array $parameters = [])
	 * @method static EO_StorageDataView_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageDataView createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageDataView wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection wakeUpCollection($rows)
	 */
	class StorageDataViewTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StorageDataView_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection fetchCollection()
	 */
	class EO_StorageDataView_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection fetchCollection()
	 */
	class EO_StorageDataView_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageDataView_Collection wakeUpCollection($rows)
	 */
	class EO_StorageDataView_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StorageRecordTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageRecord
	 * @see \Bitrix\Bizproc\Internal\Model\StorageRecordTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setStorageId(\int|\Bitrix\Main\DB\SqlExpression $storageId)
	 * @method bool hasStorageId()
	 * @method bool isStorageIdFilled()
	 * @method bool isStorageIdChanged()
	 * @method \int remindActualStorageId()
	 * @method \int requireStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetStorageId()
	 * @method \int fillStorageId()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \int getUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setUpdatedBy(\int|\Bitrix\Main\DB\SqlExpression $updatedBy)
	 * @method bool hasUpdatedBy()
	 * @method bool isUpdatedByFilled()
	 * @method bool isUpdatedByChanged()
	 * @method \int remindActualUpdatedBy()
	 * @method \int requireUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetUpdatedBy()
	 * @method \int fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setCreatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdTime)
	 * @method bool hasCreatedTime()
	 * @method bool isCreatedTimeFilled()
	 * @method bool isCreatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord setUpdatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedTime)
	 * @method bool hasUpdatedTime()
	 * @method bool isUpdatedTimeFilled()
	 * @method bool isUpdatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord resetUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unsetUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedTime()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecord wakeUp($data)
	 */
	class EO_StorageRecord extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageRecordTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageRecordTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageRecord_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getStorageIdList()
	 * @method \int[] fillStorageId()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \int[] getUpdatedByList()
	 * @method \int[] fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedTime()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_StorageRecord $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_StorageRecord $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_StorageRecord $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection filter(callable $callback)
	 */
	class EO_StorageRecord_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageRecordTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageRecordTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_StorageRecord_Query query()
	 * @method static EO_StorageRecord_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_StorageRecord_Result getById($id)
	 * @method static EO_StorageRecord_Result getList(array $parameters = [])
	 * @method static EO_StorageRecord_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecord createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecord wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection wakeUpCollection($rows)
	 */
	class StorageRecordTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StorageRecord_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection fetchCollection()
	 */
	class EO_StorageRecord_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection fetchCollection()
	 */
	class EO_StorageRecord_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecord_Collection wakeUpCollection($rows)
	 */
	class EO_StorageRecord_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StorageTypeTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageType
	 * @see \Bitrix\Bizproc\Internal\Model\StorageTypeTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getTitle()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setTitle(\string|\Bitrix\Main\DB\SqlExpression $title)
	 * @method bool hasTitle()
	 * @method bool isTitleFilled()
	 * @method bool isTitleChanged()
	 * @method \string remindActualTitle()
	 * @method \string requireTitle()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetTitle()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetTitle()
	 * @method \string fillTitle()
	 * @method \string getCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetCode()
	 * @method \string fillCode()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetDescription()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetDescription()
	 * @method \string fillDescription()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \int getUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setUpdatedBy(\int|\Bitrix\Main\DB\SqlExpression $updatedBy)
	 * @method bool hasUpdatedBy()
	 * @method bool isUpdatedByFilled()
	 * @method bool isUpdatedByChanged()
	 * @method \int remindActualUpdatedBy()
	 * @method \int requireUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetUpdatedBy()
	 * @method \int fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setCreatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdTime)
	 * @method bool hasCreatedTime()
	 * @method bool isCreatedTimeFilled()
	 * @method bool isCreatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType setUpdatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedTime)
	 * @method bool hasUpdatedTime()
	 * @method bool isUpdatedTimeFilled()
	 * @method bool isUpdatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType resetUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unsetUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedTime()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType wakeUp($data)
	 */
	class EO_StorageType extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageTypeTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageTypeTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageType_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getTitleList()
	 * @method \string[] fillTitle()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \int[] getUpdatedByList()
	 * @method \int[] fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedTime()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_StorageType $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_StorageType $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_StorageType $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection filter(callable $callback)
	 */
	class EO_StorageType_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageTypeTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageTypeTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_StorageType_Query query()
	 * @method static EO_StorageType_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_StorageType_Result getById($id)
	 * @method static EO_StorageType_Result getList(array $parameters = [])
	 * @method static EO_StorageType_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection wakeUpCollection($rows)
	 */
	class StorageTypeTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StorageType_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection fetchCollection()
	 */
	class EO_StorageType_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection fetchCollection()
	 */
	class EO_StorageType_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection wakeUpCollection($rows)
	 */
	class EO_StorageType_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\RobotVersionIndexTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_RobotVersionIndex
	 * @see \Bitrix\Bizproc\Internal\Model\RobotVersionIndexTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getRobotCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex setRobotCode(\string|\Bitrix\Main\DB\SqlExpression $robotCode)
	 * @method bool hasRobotCode()
	 * @method bool isRobotCodeFilled()
	 * @method bool isRobotCodeChanged()
	 * @method \string remindActualRobotCode()
	 * @method \string requireRobotCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex resetRobotCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex unsetRobotCode()
	 * @method \string fillRobotCode()
	 * @method \int getVersion()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex setVersion(\int|\Bitrix\Main\DB\SqlExpression $version)
	 * @method bool hasVersion()
	 * @method bool isVersionFilled()
	 * @method bool isVersionChanged()
	 * @method \int remindActualVersion()
	 * @method \int requireVersion()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex resetVersion()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex unsetVersion()
	 * @method \int fillVersion()
	 * @method \Bitrix\Main\Type\Date getDateChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex setDateChanged(\Bitrix\Main\Type\Date|\Bitrix\Main\DB\SqlExpression $dateChanged)
	 * @method bool hasDateChanged()
	 * @method bool isDateChangedFilled()
	 * @method bool isDateChangedChanged()
	 * @method \Bitrix\Main\Type\Date remindActualDateChanged()
	 * @method \Bitrix\Main\Type\Date requireDateChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex resetDateChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex unsetDateChanged()
	 * @method \Bitrix\Main\Type\Date fillDateChanged()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex wakeUp($data)
	 */
	class EO_RobotVersionIndex extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\RobotVersionIndexTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\RobotVersionIndexTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_RobotVersionIndex_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getRobotCodeList()
	 * @method \string[] fillRobotCode()
	 * @method \int[] getVersionList()
	 * @method \int[] fillVersion()
	 * @method \Bitrix\Main\Type\Date[] getDateChangedList()
	 * @method \Bitrix\Main\Type\Date[] fillDateChanged()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection filter(callable $callback)
	 */
	class EO_RobotVersionIndex_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\RobotVersionIndexTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\RobotVersionIndexTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_RobotVersionIndex_Query query()
	 * @method static EO_RobotVersionIndex_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_RobotVersionIndex_Result getById($id)
	 * @method static EO_RobotVersionIndex_Result getList(array $parameters = [])
	 * @method static EO_RobotVersionIndex_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection wakeUpCollection($rows)
	 */
	class RobotVersionIndexTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RobotVersionIndex_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection fetchCollection()
	 */
	class EO_RobotVersionIndex_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection fetchCollection()
	 */
	class EO_RobotVersionIndex_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_RobotVersionIndex_Collection wakeUpCollection($rows)
	 */
	class EO_RobotVersionIndex_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\PermissionTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_Permission
	 * @see \Bitrix\Bizproc\Internal\Model\PermissionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getRoleId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission setRoleId(\int|\Bitrix\Main\DB\SqlExpression $roleId)
	 * @method bool hasRoleId()
	 * @method bool isRoleIdFilled()
	 * @method bool isRoleIdChanged()
	 * @method \int remindActualRoleId()
	 * @method \int requireRoleId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission resetRoleId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission unsetRoleId()
	 * @method \int fillRoleId()
	 * @method \string getPermissionId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission setPermissionId(\string|\Bitrix\Main\DB\SqlExpression $permissionId)
	 * @method bool hasPermissionId()
	 * @method bool isPermissionIdFilled()
	 * @method bool isPermissionIdChanged()
	 * @method \string remindActualPermissionId()
	 * @method \string requirePermissionId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission resetPermissionId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission unsetPermissionId()
	 * @method \string fillPermissionId()
	 * @method \int getValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission setValue(\int|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \int remindActualValue()
	 * @method \int requireValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission resetValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission unsetValue()
	 * @method \int fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Permission wakeUp($data)
	 */
	class EO_Permission extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\PermissionTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\PermissionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_Permission_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getRoleIdList()
	 * @method \int[] fillRoleId()
	 * @method \string[] getPermissionIdList()
	 * @method \string[] fillPermissionId()
	 * @method \int[] getValueList()
	 * @method \int[] fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_Permission $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_Permission $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_Permission $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_Permission_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection filter(callable $callback)
	 */
	class EO_Permission_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\PermissionTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\PermissionTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_Permission_Query query()
	 * @method static EO_Permission_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Permission_Result getById($id)
	 * @method static EO_Permission_Result getList(array $parameters = [])
	 * @method static EO_Permission_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Permission createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Permission wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection wakeUpCollection($rows)
	 */
	class PermissionTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Permission_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection fetchCollection()
	 */
	class EO_Permission_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection fetchCollection()
	 */
	class EO_Permission_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Permission_Collection wakeUpCollection($rows)
	 */
	class EO_Permission_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable */
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * EO_DebugTrace
	 * @see \Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getDebugSessionId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setDebugSessionId(\int|\Bitrix\Main\DB\SqlExpression $debugSessionId)
	 * @method bool hasDebugSessionId()
	 * @method bool isDebugSessionIdFilled()
	 * @method bool isDebugSessionIdChanged()
	 * @method \int remindActualDebugSessionId()
	 * @method \int requireDebugSessionId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetDebugSessionId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetDebugSessionId()
	 * @method \int fillDebugSessionId()
	 * @method \string getKey()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setKey(\string|\Bitrix\Main\DB\SqlExpression $key)
	 * @method bool hasKey()
	 * @method bool isKeyFilled()
	 * @method bool isKeyChanged()
	 * @method \string remindActualKey()
	 * @method \string requireKey()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetKey()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetKey()
	 * @method \string fillKey()
	 * @method \string getType()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetType()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetType()
	 * @method \string fillType()
	 * @method null|\string getMessage()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setMessage(null|\string|\Bitrix\Main\DB\SqlExpression $message)
	 * @method bool hasMessage()
	 * @method bool isMessageFilled()
	 * @method bool isMessageChanged()
	 * @method null|\string remindActualMessage()
	 * @method null|\string requireMessage()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetMessage()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetMessage()
	 * @method null|\string fillMessage()
	 * @method null|\string getData()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setData(null|\string|\Bitrix\Main\DB\SqlExpression $data)
	 * @method bool hasData()
	 * @method bool isDataFilled()
	 * @method bool isDataChanged()
	 * @method null|\string remindActualData()
	 * @method null|\string requireData()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetData()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetData()
	 * @method null|\string fillData()
	 * @method null|\string getContext()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setContext(null|\string|\Bitrix\Main\DB\SqlExpression $context)
	 * @method bool hasContext()
	 * @method bool isContextFilled()
	 * @method bool isContextChanged()
	 * @method null|\string remindActualContext()
	 * @method null|\string requireContext()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetContext()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetContext()
	 * @method null|\string fillContext()
	 * @method \float getTimestamp()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setTimestamp(\float|\Bitrix\Main\DB\SqlExpression $timestamp)
	 * @method bool hasTimestamp()
	 * @method bool isTimestampFilled()
	 * @method bool isTimestampChanged()
	 * @method \float remindActualTimestamp()
	 * @method \float requireTimestamp()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetTimestamp()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetTimestamp()
	 * @method \float fillTimestamp()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession getDebugSession()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession remindActualDebugSession()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession requireDebugSession()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace setDebugSession(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession $object)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace resetDebugSession()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unsetDebugSession()
	 * @method bool hasDebugSession()
	 * @method bool isDebugSessionFilled()
	 * @method bool isDebugSessionChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession fillDebugSession()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace wakeUp($data)
	 */
	class EO_DebugTrace extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * EO_DebugTrace_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getDebugSessionIdList()
	 * @method \int[] fillDebugSessionId()
	 * @method \string[] getKeyList()
	 * @method \string[] fillKey()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method null|\string[] getMessageList()
	 * @method null|\string[] fillMessage()
	 * @method null|\string[] getDataList()
	 * @method null|\string[] fillData()
	 * @method null|\string[] getContextList()
	 * @method null|\string[] fillContext()
	 * @method \float[] getTimestampList()
	 * @method \float[] fillTimestamp()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession[] getDebugSessionList()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection getDebugSessionCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection fillDebugSession()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection merge(?\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection filter(callable $callback)
	 */
	class EO_DebugTrace_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * @method static EO_DebugTrace_Query query()
	 * @method static EO_DebugTrace_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DebugTrace_Result getById($id)
	 * @method static EO_DebugTrace_Result getList(array $parameters = [])
	 * @method static EO_DebugTrace_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection wakeUpCollection($rows)
	 */
	class DebugTraceTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DebugTrace_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection fetchCollection()
	 */
	class EO_DebugTrace_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection fetchCollection()
	 */
	class EO_DebugTrace_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection wakeUpCollection($rows)
	 */
	class EO_DebugTrace_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable */
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * EO_DebugSession
	 * @see \Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getDebugId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setDebugId(\int|\Bitrix\Main\DB\SqlExpression $debugId)
	 * @method bool hasDebugId()
	 * @method bool isDebugIdFilled()
	 * @method bool isDebugIdChanged()
	 * @method \int remindActualDebugId()
	 * @method \int requireDebugId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetDebugId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetDebugId()
	 * @method \int fillDebugId()
	 * @method null|\string getModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setModuleId(null|\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method null|\string remindActualModuleId()
	 * @method null|\string requireModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetModuleId()
	 * @method null|\string fillModuleId()
	 * @method null|\string getEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setEntity(null|\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method null|\string remindActualEntity()
	 * @method null|\string requireEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetEntity()
	 * @method null|\string fillEntity()
	 * @method null|\string getDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setDocumentId(null|\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method null|\string remindActualDocumentId()
	 * @method null|\string requireDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetDocumentId()
	 * @method null|\string fillDocumentId()
	 * @method null|\string getWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setWorkflowId(null|\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method null|\string remindActualWorkflowId()
	 * @method null|\string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetWorkflowId()
	 * @method null|\string fillWorkflowId()
	 * @method null|\int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setTemplateId(null|\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method null|\int remindActualTemplateId()
	 * @method null|\int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetTemplateId()
	 * @method null|\int fillTemplateId()
	 * @method \float getStartTime()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setStartTime(\float|\Bitrix\Main\DB\SqlExpression $startTime)
	 * @method bool hasStartTime()
	 * @method bool isStartTimeFilled()
	 * @method bool isStartTimeChanged()
	 * @method \float remindActualStartTime()
	 * @method \float requireStartTime()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetStartTime()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetStartTime()
	 * @method \float fillStartTime()
	 * @method null|\float getEndTime()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setEndTime(null|\float|\Bitrix\Main\DB\SqlExpression $endTime)
	 * @method bool hasEndTime()
	 * @method bool isEndTimeFilled()
	 * @method bool isEndTimeChanged()
	 * @method null|\float remindActualEndTime()
	 * @method null|\float requireEndTime()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetEndTime()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetEndTime()
	 * @method null|\float fillEndTime()
	 * @method null|\string getMetadata()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setMetadata(null|\string|\Bitrix\Main\DB\SqlExpression $metadata)
	 * @method bool hasMetadata()
	 * @method bool isMetadataFilled()
	 * @method bool isMetadataChanged()
	 * @method null|\string remindActualMetadata()
	 * @method null|\string requireMetadata()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetMetadata()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetMetadata()
	 * @method null|\string fillMetadata()
	 * @method null|\string getLogs()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setLogs(null|\string|\Bitrix\Main\DB\SqlExpression $logs)
	 * @method bool hasLogs()
	 * @method bool isLogsFilled()
	 * @method bool isLogsChanged()
	 * @method null|\string remindActualLogs()
	 * @method null|\string requireLogs()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetLogs()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetLogs()
	 * @method null|\string fillLogs()
	 * @method null|\string getMetrics()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setMetrics(null|\string|\Bitrix\Main\DB\SqlExpression $metrics)
	 * @method bool hasMetrics()
	 * @method bool isMetricsFilled()
	 * @method bool isMetricsChanged()
	 * @method null|\string remindActualMetrics()
	 * @method null|\string requireMetrics()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetMetrics()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetMetrics()
	 * @method null|\string fillMetrics()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection getDebugTraces()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection requireDebugTraces()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection fillDebugTraces()
	 * @method bool hasDebugTraces()
	 * @method bool isDebugTracesFilled()
	 * @method bool isDebugTracesChanged()
	 * @method void addToDebugTraces(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace $debugTrace)
	 * @method void removeFromDebugTraces(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace $debugTrace)
	 * @method void removeAllDebugTraces()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetDebugTraces()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetDebugTraces()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug getDebug()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug remindActualDebug()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug requireDebug()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession setDebug(\Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug $object)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession resetDebug()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unsetDebug()
	 * @method bool hasDebug()
	 * @method bool isDebugFilled()
	 * @method bool isDebugChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug fillDebug()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession wakeUp($data)
	 */
	class EO_DebugSession extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * EO_DebugSession_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getDebugIdList()
	 * @method \int[] fillDebugId()
	 * @method null|\string[] getModuleIdList()
	 * @method null|\string[] fillModuleId()
	 * @method null|\string[] getEntityList()
	 * @method null|\string[] fillEntity()
	 * @method null|\string[] getDocumentIdList()
	 * @method null|\string[] fillDocumentId()
	 * @method null|\string[] getWorkflowIdList()
	 * @method null|\string[] fillWorkflowId()
	 * @method null|\int[] getTemplateIdList()
	 * @method null|\int[] fillTemplateId()
	 * @method \float[] getStartTimeList()
	 * @method \float[] fillStartTime()
	 * @method null|\float[] getEndTimeList()
	 * @method null|\float[] fillEndTime()
	 * @method null|\string[] getMetadataList()
	 * @method null|\string[] fillMetadata()
	 * @method null|\string[] getLogsList()
	 * @method null|\string[] fillLogs()
	 * @method null|\string[] getMetricsList()
	 * @method null|\string[] fillMetrics()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection[] getDebugTracesList()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection getDebugTracesCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection fillDebugTraces()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug[] getDebugList()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection getDebugCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection fillDebug()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection merge(?\Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection filter(callable $callback)
	 */
	class EO_DebugSession_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * @method static EO_DebugSession_Query query()
	 * @method static EO_DebugSession_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DebugSession_Result getById($id)
	 * @method static EO_DebugSession_Result getList(array $parameters = [])
	 * @method static EO_DebugSession_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection wakeUpCollection($rows)
	 */
	class DebugSessionTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DebugSession_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection fetchCollection()
	 */
	class EO_DebugSession_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection fetchCollection()
	 */
	class EO_DebugSession_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection wakeUpCollection($rows)
	 */
	class EO_DebugSession_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\Debugger\DebugTable */
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * EO_Debug
	 * @see \Bitrix\Bizproc\Internal\Model\Debugger\DebugTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method null|\string getModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setModuleId(null|\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method null|\string remindActualModuleId()
	 * @method null|\string requireModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetModuleId()
	 * @method null|\string fillModuleId()
	 * @method null|\string getEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setEntity(null|\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method null|\string remindActualEntity()
	 * @method null|\string requireEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetEntity()
	 * @method null|\string fillEntity()
	 * @method null|\string getDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setDocumentId(null|\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method null|\string remindActualDocumentId()
	 * @method null|\string requireDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetDocumentId()
	 * @method null|\string fillDocumentId()
	 * @method \string getEnabled()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setEnabled(\string|\Bitrix\Main\DB\SqlExpression $enabled)
	 * @method bool hasEnabled()
	 * @method bool isEnabledFilled()
	 * @method bool isEnabledChanged()
	 * @method \string remindActualEnabled()
	 * @method \string requireEnabled()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetEnabled()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetEnabled()
	 * @method \string fillEnabled()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug wakeUp($data)
	 */
	class EO_Debug extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\Debugger\DebugTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Debugger\DebugTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * EO_Debug_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method null|\string[] getModuleIdList()
	 * @method null|\string[] fillModuleId()
	 * @method null|\string[] getEntityList()
	 * @method null|\string[] fillEntity()
	 * @method null|\string[] getDocumentIdList()
	 * @method null|\string[] fillDocumentId()
	 * @method \string[] getEnabledList()
	 * @method \string[] fillEnabled()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection merge(?\Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection filter(callable $callback)
	 */
	class EO_Debug_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\Debugger\DebugTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Debugger\DebugTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\Debugger {
	/**
	 * @method static EO_Debug_Query query()
	 * @method static EO_Debug_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Debug_Result getById($id)
	 * @method static EO_Debug_Result getList(array $parameters = [])
	 * @method static EO_Debug_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection wakeUpCollection($rows)
	 */
	class DebugTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Debug_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection fetchCollection()
	 */
	class EO_Debug_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection fetchCollection()
	 */
	class EO_Debug_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection wakeUpCollection($rows)
	 */
	class EO_Debug_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StorageRecordDataTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageRecordData
	 * @see \Bitrix\Bizproc\Internal\Model\StorageRecordDataTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setStorageId(\int|\Bitrix\Main\DB\SqlExpression $storageId)
	 * @method bool hasStorageId()
	 * @method bool isStorageIdFilled()
	 * @method bool isStorageIdChanged()
	 * @method \int remindActualStorageId()
	 * @method \int requireStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetStorageId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetStorageId()
	 * @method \int fillStorageId()
	 * @method \string getCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetCode()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetCode()
	 * @method \string fillCode()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetDocumentId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetWorkflowId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \int getUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setUpdatedBy(\int|\Bitrix\Main\DB\SqlExpression $updatedBy)
	 * @method bool hasUpdatedBy()
	 * @method bool isUpdatedByFilled()
	 * @method bool isUpdatedByChanged()
	 * @method \int remindActualUpdatedBy()
	 * @method \int requireUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetUpdatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetUpdatedBy()
	 * @method \int fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setCreatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdTime)
	 * @method bool hasCreatedTime()
	 * @method bool isCreatedTimeFilled()
	 * @method bool isCreatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetCreatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setUpdatedTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedTime)
	 * @method bool hasUpdatedTime()
	 * @method bool isUpdatedTimeFilled()
	 * @method bool isUpdatedTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetUpdatedTime()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetUpdatedTime()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedTime()
	 * @method array getValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData setValue(array|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method array remindActualValue()
	 * @method array requireValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData resetValue()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unsetValue()
	 * @method array fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData wakeUp($data)
	 */
	class EO_StorageRecordData extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageRecordDataTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageRecordDataTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_StorageRecordData_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getStorageIdList()
	 * @method \int[] fillStorageId()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \int[] getUpdatedByList()
	 * @method \int[] fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedTime()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedTime()
	 * @method array[] getValueList()
	 * @method array[] fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_StorageRecordData $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_StorageRecordData $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_StorageRecordData $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection filter(callable $callback)
	 */
	class EO_StorageRecordData_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StorageRecordDataTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StorageRecordDataTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_StorageRecordData_Query query()
	 * @method static EO_StorageRecordData_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_StorageRecordData_Result getById($id)
	 * @method static EO_StorageRecordData_Result getList(array $parameters = [])
	 * @method static EO_StorageRecordData_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection wakeUpCollection($rows)
	 */
	class StorageRecordDataTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_StorageRecordData_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection fetchCollection()
	 */
	class EO_StorageRecordData_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection fetchCollection()
	 */
	class EO_StorageRecordData_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_StorageRecordData_Collection wakeUpCollection($rows)
	 */
	class EO_StorageRecordData_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable */
namespace Bitrix\Bizproc\Internal\Model\Pilot {
	/**
	 * EO_WorkflowTemplatePilot
	 * @see \Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetModuleId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetEntity()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetDocumentType()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method array getTemplateData()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setTemplateData(array|\Bitrix\Main\DB\SqlExpression $templateData)
	 * @method bool hasTemplateData()
	 * @method bool isTemplateDataFilled()
	 * @method bool isTemplateDataChanged()
	 * @method array remindActualTemplateData()
	 * @method array requireTemplateData()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetTemplateData()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetTemplateData()
	 * @method array fillTemplateData()
	 * @method \string getRevision()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setRevision(\string|\Bitrix\Main\DB\SqlExpression $revision)
	 * @method bool hasRevision()
	 * @method bool isRevisionFilled()
	 * @method bool isRevisionChanged()
	 * @method \string remindActualRevision()
	 * @method \string requireRevision()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetRevision()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetRevision()
	 * @method \string fillRevision()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetCreatedBy()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot resetCreated()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot wakeUp($data)
	 */
	class EO_WorkflowTemplatePilot extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\Pilot {
	/**
	 * EO_WorkflowTemplatePilot_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method array[] getTemplateDataList()
	 * @method array[] fillTemplateData()
	 * @method \string[] getRevisionList()
	 * @method \string[] fillRevision()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection merge(?\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplatePilot_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\Pilot {
	/**
	 * @method static EO_WorkflowTemplatePilot_Query query()
	 * @method static EO_WorkflowTemplatePilot_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplatePilot_Result getById($id)
	 * @method static EO_WorkflowTemplatePilot_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplatePilot_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplatePilotTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplatePilot_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection fetchCollection()
	 */
	class EO_WorkflowTemplatePilot_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection fetchCollection()
	 */
	class EO_WorkflowTemplatePilot_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplatePilot_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable */
namespace Bitrix\Bizproc\Internal\Model\Pilot {
	/**
	 * EO_WorkflowTemplatePilotAccess
	 * @see \Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getPilotId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess setPilotId(\int|\Bitrix\Main\DB\SqlExpression $pilotId)
	 * @method bool hasPilotId()
	 * @method bool isPilotIdFilled()
	 * @method bool isPilotIdChanged()
	 * @method \int remindActualPilotId()
	 * @method \int requirePilotId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess resetPilotId()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess unsetPilotId()
	 * @method \int fillPilotId()
	 * @method \string getAccessCode()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess setAccessCode(\string|\Bitrix\Main\DB\SqlExpression $accessCode)
	 * @method bool hasAccessCode()
	 * @method bool isAccessCodeFilled()
	 * @method bool isAccessCodeChanged()
	 * @method \string remindActualAccessCode()
	 * @method \string requireAccessCode()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess resetAccessCode()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess unsetAccessCode()
	 * @method \string fillAccessCode()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess wakeUp($data)
	 */
	class EO_WorkflowTemplatePilotAccess extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\Pilot {
	/**
	 * EO_WorkflowTemplatePilotAccess_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getPilotIdList()
	 * @method \int[] fillPilotId()
	 * @method \string[] getAccessCodeList()
	 * @method \string[] fillAccessCode()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection merge(?\Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplatePilotAccess_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\Pilot {
	/**
	 * @method static EO_WorkflowTemplatePilotAccess_Query query()
	 * @method static EO_WorkflowTemplatePilotAccess_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplatePilotAccess_Result getById($id)
	 * @method static EO_WorkflowTemplatePilotAccess_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplatePilotAccess_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplatePilotAccessTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplatePilotAccess_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection fetchCollection()
	 */
	class EO_WorkflowTemplatePilotAccess_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection fetchCollection()
	 */
	class EO_WorkflowTemplatePilotAccess_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplatePilotAccess_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\RoleTable */
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_Role
	 * @see \Bitrix\Bizproc\Internal\Model\RoleTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role resetName()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role unsetName()
	 * @method \string fillName()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role wakeUp($data)
	 */
	class EO_Role extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\RoleTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\RoleTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * EO_Role_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\EO_Role $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\EO_Role $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\EO_Role $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role_Collection merge(?\Bitrix\Bizproc\Internal\Model\EO_Role_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role_Collection filter(callable $callback)
	 */
	class EO_Role_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\RoleTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\RoleTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model {
	/**
	 * @method static EO_Role_Query query()
	 * @method static EO_Role_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Role_Result getById($id)
	 * @method static EO_Role_Result getList(array $parameters = [])
	 * @method static EO_Role_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\EO_Role_Collection wakeUpCollection($rows)
	 */
	class RoleTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Role_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role_Collection fetchCollection()
	 */
	class EO_Role_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role_Collection fetchCollection()
	 */
	class EO_Role_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\EO_Role_Collection wakeUpCollection($rows)
	 */
	class EO_Role_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable */
namespace Bitrix\Bizproc\Internal\Model\AiAgent {
	/**
	 * EO_ManagedAgentResource
	 * @see \Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getInstanceId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource setInstanceId(\int|\Bitrix\Main\DB\SqlExpression $instanceId)
	 * @method bool hasInstanceId()
	 * @method bool isInstanceIdFilled()
	 * @method bool isInstanceIdChanged()
	 * @method \int remindActualInstanceId()
	 * @method \int requireInstanceId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource resetInstanceId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource unsetInstanceId()
	 * @method \int fillInstanceId()
	 * @method \string getType()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource resetType()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource unsetType()
	 * @method \string fillType()
	 * @method \string getResourceId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource setResourceId(\string|\Bitrix\Main\DB\SqlExpression $resourceId)
	 * @method bool hasResourceId()
	 * @method bool isResourceIdFilled()
	 * @method bool isResourceIdChanged()
	 * @method \string remindActualResourceId()
	 * @method \string requireResourceId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource resetResourceId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource unsetResourceId()
	 * @method \string fillResourceId()
	 * @method null|\string getData()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource setData(null|\string|\Bitrix\Main\DB\SqlExpression $data)
	 * @method bool hasData()
	 * @method bool isDataFilled()
	 * @method bool isDataChanged()
	 * @method null|\string remindActualData()
	 * @method null|\string requireData()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource resetData()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource unsetData()
	 * @method null|\string fillData()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource wakeUp($data)
	 */
	class EO_ManagedAgentResource extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\AiAgent {
	/**
	 * EO_ManagedAgentResource_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getInstanceIdList()
	 * @method \int[] fillInstanceId()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \string[] getResourceIdList()
	 * @method \string[] fillResourceId()
	 * @method null|\string[] getDataList()
	 * @method null|\string[] fillData()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection merge(?\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection filter(callable $callback)
	 */
	class EO_ManagedAgentResource_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\AiAgent {
	/**
	 * @method static EO_ManagedAgentResource_Query query()
	 * @method static EO_ManagedAgentResource_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_ManagedAgentResource_Result getById($id)
	 * @method static EO_ManagedAgentResource_Result getList(array $parameters = [])
	 * @method static EO_ManagedAgentResource_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection wakeUpCollection($rows)
	 */
	class ManagedAgentResourceTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ManagedAgentResource_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection fetchCollection()
	 */
	class EO_ManagedAgentResource_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection fetchCollection()
	 */
	class EO_ManagedAgentResource_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection wakeUpCollection($rows)
	 */
	class EO_ManagedAgentResource_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable */
namespace Bitrix\Bizproc\Internal\Model\AiAgent {
	/**
	 * EO_ManagedAgentInstance
	 * @see \Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getIdentityHash()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setIdentityHash(\string|\Bitrix\Main\DB\SqlExpression $identityHash)
	 * @method bool hasIdentityHash()
	 * @method bool isIdentityHashFilled()
	 * @method bool isIdentityHashChanged()
	 * @method \string remindActualIdentityHash()
	 * @method \string requireIdentityHash()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetIdentityHash()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetIdentityHash()
	 * @method \string fillIdentityHash()
	 * @method \string getSystemCode()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setSystemCode(\string|\Bitrix\Main\DB\SqlExpression $systemCode)
	 * @method bool hasSystemCode()
	 * @method bool isSystemCodeFilled()
	 * @method bool isSystemCodeChanged()
	 * @method \string remindActualSystemCode()
	 * @method \string requireSystemCode()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetSystemCode()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetSystemCode()
	 * @method \string fillSystemCode()
	 * @method \string getContextNamespace()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setContextNamespace(\string|\Bitrix\Main\DB\SqlExpression $contextNamespace)
	 * @method bool hasContextNamespace()
	 * @method bool isContextNamespaceFilled()
	 * @method bool isContextNamespaceChanged()
	 * @method \string remindActualContextNamespace()
	 * @method \string requireContextNamespace()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetContextNamespace()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetContextNamespace()
	 * @method \string fillContextNamespace()
	 * @method \string getContextType()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setContextType(\string|\Bitrix\Main\DB\SqlExpression $contextType)
	 * @method bool hasContextType()
	 * @method bool isContextTypeFilled()
	 * @method bool isContextTypeChanged()
	 * @method \string remindActualContextType()
	 * @method \string requireContextType()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetContextType()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetContextType()
	 * @method \string fillContextType()
	 * @method \string getContextId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setContextId(\string|\Bitrix\Main\DB\SqlExpression $contextId)
	 * @method bool hasContextId()
	 * @method bool isContextIdFilled()
	 * @method bool isContextIdChanged()
	 * @method \string remindActualContextId()
	 * @method \string requireContextId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetContextId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetContextId()
	 * @method \string fillContextId()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetUserId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetUserId()
	 * @method \int fillUserId()
	 * @method null|\int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setTemplateId(null|\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method null|\int remindActualTemplateId()
	 * @method null|\int requireTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetTemplateId()
	 * @method null|\int fillTemplateId()
	 * @method \string getState()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setState(\string|\Bitrix\Main\DB\SqlExpression $state)
	 * @method bool hasState()
	 * @method bool isStateFilled()
	 * @method bool isStateChanged()
	 * @method \string remindActualState()
	 * @method \string requireState()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetState()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetState()
	 * @method \string fillState()
	 * @method \string getConfigFingerprint()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setConfigFingerprint(\string|\Bitrix\Main\DB\SqlExpression $configFingerprint)
	 * @method bool hasConfigFingerprint()
	 * @method bool isConfigFingerprintFilled()
	 * @method bool isConfigFingerprintChanged()
	 * @method \string remindActualConfigFingerprint()
	 * @method \string requireConfigFingerprint()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetConfigFingerprint()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetConfigFingerprint()
	 * @method \string fillConfigFingerprint()
	 * @method \int getRetryCount()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setRetryCount(\int|\Bitrix\Main\DB\SqlExpression $retryCount)
	 * @method bool hasRetryCount()
	 * @method bool isRetryCountFilled()
	 * @method bool isRetryCountChanged()
	 * @method \int remindActualRetryCount()
	 * @method \int requireRetryCount()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetRetryCount()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetRetryCount()
	 * @method \int fillRetryCount()
	 * @method null|\Bitrix\Main\Type\DateTime getNextRetryAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setNextRetryAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $nextRetryAt)
	 * @method bool hasNextRetryAt()
	 * @method bool isNextRetryAtFilled()
	 * @method bool isNextRetryAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualNextRetryAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireNextRetryAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetNextRetryAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetNextRetryAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillNextRetryAt()
	 * @method null|\string getLastErrorCode()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setLastErrorCode(null|\string|\Bitrix\Main\DB\SqlExpression $lastErrorCode)
	 * @method bool hasLastErrorCode()
	 * @method bool isLastErrorCodeFilled()
	 * @method bool isLastErrorCodeChanged()
	 * @method null|\string remindActualLastErrorCode()
	 * @method null|\string requireLastErrorCode()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetLastErrorCode()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetLastErrorCode()
	 * @method null|\string fillLastErrorCode()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetCreatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance setUpdatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $updatedAt)
	 * @method bool hasUpdatedAt()
	 * @method bool isUpdatedAtFilled()
	 * @method bool isUpdatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance resetUpdatedAt()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unsetUpdatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillUpdatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance wakeUp($data)
	 */
	class EO_ManagedAgentInstance extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\AiAgent {
	/**
	 * EO_ManagedAgentInstance_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getIdentityHashList()
	 * @method \string[] fillIdentityHash()
	 * @method \string[] getSystemCodeList()
	 * @method \string[] fillSystemCode()
	 * @method \string[] getContextNamespaceList()
	 * @method \string[] fillContextNamespace()
	 * @method \string[] getContextTypeList()
	 * @method \string[] fillContextType()
	 * @method \string[] getContextIdList()
	 * @method \string[] fillContextId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method null|\int[] getTemplateIdList()
	 * @method null|\int[] fillTemplateId()
	 * @method \string[] getStateList()
	 * @method \string[] fillState()
	 * @method \string[] getConfigFingerprintList()
	 * @method \string[] fillConfigFingerprint()
	 * @method \int[] getRetryCountList()
	 * @method \int[] fillRetryCount()
	 * @method null|\Bitrix\Main\Type\DateTime[] getNextRetryAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillNextRetryAt()
	 * @method null|\string[] getLastErrorCodeList()
	 * @method null|\string[] fillLastErrorCode()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getUpdatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUpdatedAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection merge(?\Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection filter(callable $callback)
	 */
	class EO_ManagedAgentInstance_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\AiAgent {
	/**
	 * @method static EO_ManagedAgentInstance_Query query()
	 * @method static EO_ManagedAgentInstance_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_ManagedAgentInstance_Result getById($id)
	 * @method static EO_ManagedAgentInstance_Result getList(array $parameters = [])
	 * @method static EO_ManagedAgentInstance_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection wakeUpCollection($rows)
	 */
	class ManagedAgentInstanceTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ManagedAgentInstance_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection fetchCollection()
	 */
	class EO_ManagedAgentInstance_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection fetchCollection()
	 */
	class EO_ManagedAgentInstance_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection wakeUpCollection($rows)
	 */
	class EO_ManagedAgentInstance_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Storage\Entity\ActivityStorageTable */
namespace Bitrix\Bizproc\Storage\Entity {
	/**
	 * EO_ActivityStorage
	 * @see \Bitrix\Bizproc\Storage\Entity\ActivityStorageTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage setWorkflowTemplateId(\int|\Bitrix\Main\DB\SqlExpression $workflowTemplateId)
	 * @method bool hasWorkflowTemplateId()
	 * @method bool isWorkflowTemplateIdFilled()
	 * @method bool isWorkflowTemplateIdChanged()
	 * @method \int remindActualWorkflowTemplateId()
	 * @method \int requireWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage resetWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage unsetWorkflowTemplateId()
	 * @method \int fillWorkflowTemplateId()
	 * @method \string getActivityName()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage setActivityName(\string|\Bitrix\Main\DB\SqlExpression $activityName)
	 * @method bool hasActivityName()
	 * @method bool isActivityNameFilled()
	 * @method bool isActivityNameChanged()
	 * @method \string remindActualActivityName()
	 * @method \string requireActivityName()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage resetActivityName()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage unsetActivityName()
	 * @method \string fillActivityName()
	 * @method \string getKeyId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage setKeyId(\string|\Bitrix\Main\DB\SqlExpression $keyId)
	 * @method bool hasKeyId()
	 * @method bool isKeyIdFilled()
	 * @method bool isKeyIdChanged()
	 * @method \string remindActualKeyId()
	 * @method \string requireKeyId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage resetKeyId()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage unsetKeyId()
	 * @method \string fillKeyId()
	 * @method \string getKeyValue()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage setKeyValue(\string|\Bitrix\Main\DB\SqlExpression $keyValue)
	 * @method bool hasKeyValue()
	 * @method bool isKeyValueFilled()
	 * @method bool isKeyValueChanged()
	 * @method \string remindActualKeyValue()
	 * @method \string requireKeyValue()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage resetKeyValue()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage unsetKeyValue()
	 * @method \string fillKeyValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage reset($fieldName)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage wakeUp($data)
	 */
	class EO_ActivityStorage extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Storage\Entity\ActivityStorageTable */
		static public $dataClass = '\Bitrix\Bizproc\Storage\Entity\ActivityStorageTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Storage\Entity {
	/**
	 * EO_ActivityStorage_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getWorkflowTemplateIdList()
	 * @method \int[] fillWorkflowTemplateId()
	 * @method \string[] getActivityNameList()
	 * @method \string[] fillActivityName()
	 * @method \string[] getKeyIdList()
	 * @method \string[] fillKeyId()
	 * @method \string[] getKeyValueList()
	 * @method \string[] fillKeyValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage $object)
	 * @method bool has(\Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection merge(?\Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection filter(callable $callback)
	 */
	class EO_ActivityStorage_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Storage\Entity\ActivityStorageTable */
		static public $dataClass = '\Bitrix\Bizproc\Storage\Entity\ActivityStorageTable';
	}
}
namespace Bitrix\Bizproc\Storage\Entity {
	/**
	 * @method static EO_ActivityStorage_Query query()
	 * @method static EO_ActivityStorage_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_ActivityStorage_Result getById($id)
	 * @method static EO_ActivityStorage_Result getList(array $parameters = [])
	 * @method static EO_ActivityStorage_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection wakeUpCollection($rows)
	 */
	class ActivityStorageTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ActivityStorage_Result exec()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage fetchObject()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection fetchCollection()
	 */
	class EO_ActivityStorage_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage fetchObject()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection fetchCollection()
	 */
	class EO_ActivityStorage_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection createCollection()
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Storage\Entity\EO_ActivityStorage_Collection wakeUpCollection($rows)
	 */
	class EO_ActivityStorage_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\RestProviderTable */
namespace Bitrix\Bizproc {
	/**
	 * EO_RestProvider
	 * @see \Bitrix\Bizproc\RestProviderTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\EO_RestProvider setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getAppId()
	 * @method \Bitrix\Bizproc\EO_RestProvider setAppId(\string|\Bitrix\Main\DB\SqlExpression $appId)
	 * @method bool hasAppId()
	 * @method bool isAppIdFilled()
	 * @method bool isAppIdChanged()
	 * @method \string remindActualAppId()
	 * @method \string requireAppId()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetAppId()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetAppId()
	 * @method \string fillAppId()
	 * @method \string getAppName()
	 * @method \Bitrix\Bizproc\EO_RestProvider setAppName(\string|\Bitrix\Main\DB\SqlExpression $appName)
	 * @method bool hasAppName()
	 * @method bool isAppNameFilled()
	 * @method bool isAppNameChanged()
	 * @method \string remindActualAppName()
	 * @method \string requireAppName()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetAppName()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetAppName()
	 * @method \string fillAppName()
	 * @method \string getCode()
	 * @method \Bitrix\Bizproc\EO_RestProvider setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetCode()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetCode()
	 * @method \string fillCode()
	 * @method \string getType()
	 * @method \Bitrix\Bizproc\EO_RestProvider setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetType()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetType()
	 * @method \string fillType()
	 * @method \string getHandler()
	 * @method \Bitrix\Bizproc\EO_RestProvider setHandler(\string|\Bitrix\Main\DB\SqlExpression $handler)
	 * @method bool hasHandler()
	 * @method bool isHandlerFilled()
	 * @method bool isHandlerChanged()
	 * @method \string remindActualHandler()
	 * @method \string requireHandler()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetHandler()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetHandler()
	 * @method \string fillHandler()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\EO_RestProvider setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetName()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\EO_RestProvider setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\EO_RestProvider resetDescription()
	 * @method \Bitrix\Bizproc\EO_RestProvider unsetDescription()
	 * @method \string fillDescription()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\EO_RestProvider set($fieldName, $value)
	 * @method \Bitrix\Bizproc\EO_RestProvider reset($fieldName)
	 * @method \Bitrix\Bizproc\EO_RestProvider unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\EO_RestProvider wakeUp($data)
	 */
	class EO_RestProvider extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\RestProviderTable */
		static public $dataClass = '\Bitrix\Bizproc\RestProviderTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc {
	/**
	 * EO_RestProvider_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getAppIdList()
	 * @method \string[] fillAppId()
	 * @method \string[] getAppNameList()
	 * @method \string[] fillAppName()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \string[] getHandlerList()
	 * @method \string[] fillHandler()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\EO_RestProvider $object)
	 * @method bool has(\Bitrix\Bizproc\EO_RestProvider $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\EO_RestProvider getByPrimary($primary)
	 * @method \Bitrix\Bizproc\EO_RestProvider[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\EO_RestProvider $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\EO_RestProvider_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\EO_RestProvider current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\EO_RestProvider_Collection merge(?\Bitrix\Bizproc\EO_RestProvider_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\EO_RestProvider|null find(callable $callback)
	 * @method \Bitrix\Bizproc\EO_RestProvider_Collection filter(callable $callback)
	 */
	class EO_RestProvider_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\RestProviderTable */
		static public $dataClass = '\Bitrix\Bizproc\RestProviderTable';
	}
}
namespace Bitrix\Bizproc {
	/**
	 * @method static EO_RestProvider_Query query()
	 * @method static EO_RestProvider_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_RestProvider_Result getById($id)
	 * @method static EO_RestProvider_Result getList(array $parameters = [])
	 * @method static EO_RestProvider_Entity getEntity()
	 * @method static \Bitrix\Bizproc\EO_RestProvider createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\EO_RestProvider_Collection createCollection()
	 * @method static \Bitrix\Bizproc\EO_RestProvider wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\EO_RestProvider_Collection wakeUpCollection($rows)
	 */
	class RestProviderTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RestProvider_Result exec()
	 * @method \Bitrix\Bizproc\EO_RestProvider fetchObject()
	 * @method \Bitrix\Bizproc\EO_RestProvider_Collection fetchCollection()
	 */
	class EO_RestProvider_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\EO_RestProvider fetchObject()
	 * @method \Bitrix\Bizproc\EO_RestProvider_Collection fetchCollection()
	 */
	class EO_RestProvider_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\EO_RestProvider createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\EO_RestProvider_Collection createCollection()
	 * @method \Bitrix\Bizproc\EO_RestProvider wakeUpObject($row)
	 * @method \Bitrix\Bizproc\EO_RestProvider_Collection wakeUpCollection($rows)
	 */
	class EO_RestProvider_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Automation\Trigger\Entity\TriggerTable */
namespace Bitrix\Bizproc\Automation\Trigger\Entity {
	/**
	 * TriggerObject
	 * @see \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetName()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetName()
	 * @method \string fillName()
	 * @method \string getCode()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetCode()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetCode()
	 * @method \string fillCode()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetModuleId()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetEntity()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetDocumentType()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \string getDocumentStatus()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setDocumentStatus(\string|\Bitrix\Main\DB\SqlExpression $documentStatus)
	 * @method bool hasDocumentStatus()
	 * @method bool isDocumentStatusFilled()
	 * @method bool isDocumentStatusChanged()
	 * @method \string remindActualDocumentStatus()
	 * @method \string requireDocumentStatus()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetDocumentStatus()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetDocumentStatus()
	 * @method \string fillDocumentStatus()
	 * @method \string getApplyRules()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject setApplyRules(\string|\Bitrix\Main\DB\SqlExpression $applyRules)
	 * @method bool hasApplyRules()
	 * @method bool isApplyRulesFilled()
	 * @method bool isApplyRulesChanged()
	 * @method \string remindActualApplyRules()
	 * @method \string requireApplyRules()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject resetApplyRules()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unsetApplyRules()
	 * @method \string fillApplyRules()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject reset($fieldName)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject wakeUp($data)
	 */
	class EO_Trigger extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerTable */
		static public $dataClass = '\Bitrix\Bizproc\Automation\Trigger\Entity\TriggerTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Automation\Trigger\Entity {
	/**
	 * EO_Trigger_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \string[] getDocumentStatusList()
	 * @method \string[] fillDocumentStatus()
	 * @method \string[] getApplyRulesList()
	 * @method \string[] fillApplyRules()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject $object)
	 * @method bool has(\Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection merge(?\Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection filter(callable $callback)
	 */
	class EO_Trigger_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerTable */
		static public $dataClass = '\Bitrix\Bizproc\Automation\Trigger\Entity\TriggerTable';
	}
}
namespace Bitrix\Bizproc\Automation\Trigger\Entity {
	/**
	 * @method static EO_Trigger_Query query()
	 * @method static EO_Trigger_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Trigger_Result getById($id)
	 * @method static EO_Trigger_Result getList(array $parameters = [])
	 * @method static EO_Trigger_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection wakeUpCollection($rows)
	 */
	class TriggerTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Trigger_Result exec()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject fetchObject()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection fetchCollection()
	 */
	class EO_Trigger_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject fetchObject()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection fetchCollection()
	 */
	class EO_Trigger_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection createCollection()
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection wakeUpCollection($rows)
	 */
	class EO_Trigger_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Script\Entity\ScriptTable */
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * EO_Script
	 * @see \Bitrix\Bizproc\Script\Entity\ScriptTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetModuleId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetEntity()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetDocumentType()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetName()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetDescription()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetDescription()
	 * @method \string fillDescription()
	 * @method \int getWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setWorkflowTemplateId(\int|\Bitrix\Main\DB\SqlExpression $workflowTemplateId)
	 * @method bool hasWorkflowTemplateId()
	 * @method bool isWorkflowTemplateIdFilled()
	 * @method bool isWorkflowTemplateIdChanged()
	 * @method \int remindActualWorkflowTemplateId()
	 * @method \int requireWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetWorkflowTemplateId()
	 * @method \int fillWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getWorkflowTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualWorkflowTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireWorkflowTemplate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setWorkflowTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetWorkflowTemplate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetWorkflowTemplate()
	 * @method bool hasWorkflowTemplate()
	 * @method bool isWorkflowTemplateFilled()
	 * @method bool isWorkflowTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillWorkflowTemplate()
	 * @method \Bitrix\Main\Type\DateTime getCreatedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setCreatedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdDate)
	 * @method bool hasCreatedDate()
	 * @method bool isCreatedDateFilled()
	 * @method bool isCreatedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetCreatedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedDate()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetCreatedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \Bitrix\Main\Type\DateTime getModifiedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setModifiedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modifiedDate)
	 * @method bool hasModifiedDate()
	 * @method bool isModifiedDateFilled()
	 * @method bool isModifiedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime requireModifiedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetModifiedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime fillModifiedDate()
	 * @method \int getModifiedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setModifiedBy(\int|\Bitrix\Main\DB\SqlExpression $modifiedBy)
	 * @method bool hasModifiedBy()
	 * @method bool isModifiedByFilled()
	 * @method bool isModifiedByChanged()
	 * @method \int remindActualModifiedBy()
	 * @method \int requireModifiedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetModifiedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetModifiedBy()
	 * @method \int fillModifiedBy()
	 * @method \string getOriginatorId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setOriginatorId(\string|\Bitrix\Main\DB\SqlExpression $originatorId)
	 * @method bool hasOriginatorId()
	 * @method bool isOriginatorIdFilled()
	 * @method bool isOriginatorIdChanged()
	 * @method \string remindActualOriginatorId()
	 * @method \string requireOriginatorId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetOriginatorId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetOriginatorId()
	 * @method \string fillOriginatorId()
	 * @method \string getOriginId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setOriginId(\string|\Bitrix\Main\DB\SqlExpression $originId)
	 * @method bool hasOriginId()
	 * @method bool isOriginIdFilled()
	 * @method bool isOriginIdChanged()
	 * @method \string remindActualOriginId()
	 * @method \string requireOriginId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetOriginId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetOriginId()
	 * @method \string fillOriginId()
	 * @method \int getSort()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetSort()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetSort()
	 * @method \int fillSort()
	 * @method \boolean getActive()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script setActive(\boolean|\Bitrix\Main\DB\SqlExpression $active)
	 * @method bool hasActive()
	 * @method bool isActiveFilled()
	 * @method bool isActiveChanged()
	 * @method \boolean remindActualActive()
	 * @method \boolean requireActive()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script resetActive()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unsetActive()
	 * @method \boolean fillActive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script reset($fieldName)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_Script wakeUp($data)
	 */
	class EO_Script extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Script\Entity\ScriptTable */
		static public $dataClass = '\Bitrix\Bizproc\Script\Entity\ScriptTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * EO_Script_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \int[] getWorkflowTemplateIdList()
	 * @method \int[] fillWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getWorkflowTemplateList()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection getWorkflowTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillWorkflowTemplate()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedDate()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModifiedDate()
	 * @method \int[] getModifiedByList()
	 * @method \int[] fillModifiedBy()
	 * @method \string[] getOriginatorIdList()
	 * @method \string[] fillOriginatorId()
	 * @method \string[] getOriginIdList()
	 * @method \string[] fillOriginId()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \boolean[] getActiveList()
	 * @method \boolean[] fillActive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Script\Entity\EO_Script $object)
	 * @method bool has(\Bitrix\Bizproc\Script\Entity\EO_Script $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Script\Entity\EO_Script $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_Script_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection merge(?\Bitrix\Bizproc\Script\Entity\EO_Script_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection filter(callable $callback)
	 */
	class EO_Script_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Script\Entity\ScriptTable */
		static public $dataClass = '\Bitrix\Bizproc\Script\Entity\ScriptTable';
	}
}
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * @method static EO_Script_Query query()
	 * @method static EO_Script_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Script_Result getById($id)
	 * @method static EO_Script_Result getList(array $parameters = [])
	 * @method static EO_Script_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_Script createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_Script_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_Script wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_Script_Collection wakeUpCollection($rows)
	 */
	class ScriptTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Script_Result exec()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script fetchObject()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection fetchCollection()
	 */
	class EO_Script_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script fetchObject()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection fetchCollection()
	 */
	class EO_Script_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection createCollection()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_Script_Collection wakeUpCollection($rows)
	 */
	class EO_Script_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Script\Entity\ScriptQueueTable */
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * EO_ScriptQueue
	 * @see \Bitrix\Bizproc\Script\Entity\ScriptQueueTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getScriptId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setScriptId(\int|\Bitrix\Main\DB\SqlExpression $scriptId)
	 * @method bool hasScriptId()
	 * @method bool isScriptIdFilled()
	 * @method bool isScriptIdChanged()
	 * @method \int remindActualScriptId()
	 * @method \int requireScriptId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetScriptId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetScriptId()
	 * @method \int fillScriptId()
	 * @method \Bitrix\Main\Type\DateTime getStartedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setStartedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $startedDate)
	 * @method bool hasStartedDate()
	 * @method bool isStartedDateFilled()
	 * @method bool isStartedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStartedDate()
	 * @method \Bitrix\Main\Type\DateTime requireStartedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetStartedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetStartedDate()
	 * @method \Bitrix\Main\Type\DateTime fillStartedDate()
	 * @method \int getStartedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setStartedBy(\int|\Bitrix\Main\DB\SqlExpression $startedBy)
	 * @method bool hasStartedBy()
	 * @method bool isStartedByFilled()
	 * @method bool isStartedByChanged()
	 * @method \int remindActualStartedBy()
	 * @method \int requireStartedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetStartedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetStartedBy()
	 * @method \int fillStartedBy()
	 * @method \Bitrix\Main\EO_User getStartedUser()
	 * @method \Bitrix\Main\EO_User remindActualStartedUser()
	 * @method \Bitrix\Main\EO_User requireStartedUser()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setStartedUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetStartedUser()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetStartedUser()
	 * @method bool hasStartedUser()
	 * @method bool isStartedUserFilled()
	 * @method bool isStartedUserChanged()
	 * @method \Bitrix\Main\EO_User fillStartedUser()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetStatus()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetStatus()
	 * @method \int fillStatus()
	 * @method \Bitrix\Main\Type\DateTime getModifiedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setModifiedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modifiedDate)
	 * @method bool hasModifiedDate()
	 * @method bool isModifiedDateFilled()
	 * @method bool isModifiedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime requireModifiedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetModifiedDate()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime fillModifiedDate()
	 * @method \int getModifiedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setModifiedBy(\int|\Bitrix\Main\DB\SqlExpression $modifiedBy)
	 * @method bool hasModifiedBy()
	 * @method bool isModifiedByFilled()
	 * @method bool isModifiedByChanged()
	 * @method \int remindActualModifiedBy()
	 * @method \int requireModifiedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetModifiedBy()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetModifiedBy()
	 * @method \int fillModifiedBy()
	 * @method array getWorkflowParameters()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue setWorkflowParameters(array|\Bitrix\Main\DB\SqlExpression $workflowParameters)
	 * @method bool hasWorkflowParameters()
	 * @method bool isWorkflowParametersFilled()
	 * @method bool isWorkflowParametersChanged()
	 * @method array remindActualWorkflowParameters()
	 * @method array requireWorkflowParameters()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue resetWorkflowParameters()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unsetWorkflowParameters()
	 * @method array fillWorkflowParameters()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue reset($fieldName)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue wakeUp($data)
	 */
	class EO_ScriptQueue extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Script\Entity\ScriptQueueTable */
		static public $dataClass = '\Bitrix\Bizproc\Script\Entity\ScriptQueueTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * EO_ScriptQueue_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getScriptIdList()
	 * @method \int[] fillScriptId()
	 * @method \Bitrix\Main\Type\DateTime[] getStartedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStartedDate()
	 * @method \int[] getStartedByList()
	 * @method \int[] fillStartedBy()
	 * @method \Bitrix\Main\EO_User[] getStartedUserList()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection getStartedUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillStartedUser()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModifiedDate()
	 * @method \int[] getModifiedByList()
	 * @method \int[] fillModifiedBy()
	 * @method array[] getWorkflowParametersList()
	 * @method array[] fillWorkflowParameters()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueue $object)
	 * @method bool has(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueue $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueue $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection merge(?\Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection filter(callable $callback)
	 */
	class EO_ScriptQueue_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Script\Entity\ScriptQueueTable */
		static public $dataClass = '\Bitrix\Bizproc\Script\Entity\ScriptQueueTable';
	}
}
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * @method static EO_ScriptQueue_Query query()
	 * @method static EO_ScriptQueue_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_ScriptQueue_Result getById($id)
	 * @method static EO_ScriptQueue_Result getList(array $parameters = [])
	 * @method static EO_ScriptQueue_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection wakeUpCollection($rows)
	 */
	class ScriptQueueTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ScriptQueue_Result exec()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue fetchObject()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection fetchCollection()
	 */
	class EO_ScriptQueue_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue fetchObject()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection fetchCollection()
	 */
	class EO_ScriptQueue_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection createCollection()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection wakeUpCollection($rows)
	 */
	class EO_ScriptQueue_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Script\Entity\ScriptQueueDocumentTable */
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * EO_ScriptQueueDocument
	 * @see \Bitrix\Bizproc\Script\Entity\ScriptQueueDocumentTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getQueueId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setQueueId(\int|\Bitrix\Main\DB\SqlExpression $queueId)
	 * @method bool hasQueueId()
	 * @method bool isQueueIdFilled()
	 * @method bool isQueueIdChanged()
	 * @method \int remindActualQueueId()
	 * @method \int requireQueueId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument resetQueueId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unsetQueueId()
	 * @method \int fillQueueId()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument resetDocumentId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument resetWorkflowId()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument resetStatus()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unsetStatus()
	 * @method \int fillStatus()
	 * @method \string getStatusMessage()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setStatusMessage(\string|\Bitrix\Main\DB\SqlExpression $statusMessage)
	 * @method bool hasStatusMessage()
	 * @method bool isStatusMessageFilled()
	 * @method bool isStatusMessageChanged()
	 * @method \string remindActualStatusMessage()
	 * @method \string requireStatusMessage()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument resetStatusMessage()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unsetStatusMessage()
	 * @method \string fillStatusMessage()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue getQueue()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue remindActualQueue()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue requireQueue()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument setQueue(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueue $object)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument resetQueue()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unsetQueue()
	 * @method bool hasQueue()
	 * @method bool isQueueFilled()
	 * @method bool isQueueChanged()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue fillQueue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument reset($fieldName)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument wakeUp($data)
	 */
	class EO_ScriptQueueDocument extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Script\Entity\ScriptQueueDocumentTable */
		static public $dataClass = '\Bitrix\Bizproc\Script\Entity\ScriptQueueDocumentTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * EO_ScriptQueueDocument_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getQueueIdList()
	 * @method \int[] fillQueueId()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \string[] getStatusMessageList()
	 * @method \string[] fillStatusMessage()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue[] getQueueList()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection getQueueCollection()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueue_Collection fillQueue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument $object)
	 * @method bool has(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection merge(?\Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection filter(callable $callback)
	 */
	class EO_ScriptQueueDocument_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Script\Entity\ScriptQueueDocumentTable */
		static public $dataClass = '\Bitrix\Bizproc\Script\Entity\ScriptQueueDocumentTable';
	}
}
namespace Bitrix\Bizproc\Script\Entity {
	/**
	 * @method static EO_ScriptQueueDocument_Query query()
	 * @method static EO_ScriptQueueDocument_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_ScriptQueueDocument_Result getById($id)
	 * @method static EO_ScriptQueueDocument_Result getList(array $parameters = [])
	 * @method static EO_ScriptQueueDocument_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection wakeUpCollection($rows)
	 */
	class ScriptQueueDocumentTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ScriptQueueDocument_Result exec()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument fetchObject()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection fetchCollection()
	 */
	class EO_ScriptQueueDocument_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument fetchObject()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection fetchCollection()
	 */
	class EO_ScriptQueueDocument_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection createCollection()
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Script\Entity\EO_ScriptQueueDocument_Collection wakeUpCollection($rows)
	 */
	class EO_ScriptQueueDocument_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\DocumentType\DocumentTypeUserOptionTable */
namespace Bitrix\Bizproc\DocumentType {
	/**
	 * EO_DocumentTypeUserOption
	 * @see \Bitrix\Bizproc\DocumentType\DocumentTypeUserOptionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption resetModuleId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption resetEntity()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption resetDocumentType()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption resetUserId()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getOptionCode()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption setOptionCode(\int|\Bitrix\Main\DB\SqlExpression $optionCode)
	 * @method bool hasOptionCode()
	 * @method bool isOptionCodeFilled()
	 * @method bool isOptionCodeChanged()
	 * @method \int remindActualOptionCode()
	 * @method \int requireOptionCode()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption resetOptionCode()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption unsetOptionCode()
	 * @method \int fillOptionCode()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption set($fieldName, $value)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption reset($fieldName)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption wakeUp($data)
	 */
	class EO_DocumentTypeUserOption extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\DocumentType\DocumentTypeUserOptionTable */
		static public $dataClass = '\Bitrix\Bizproc\DocumentType\DocumentTypeUserOptionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\DocumentType {
	/**
	 * EO_DocumentTypeUserOption_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getOptionCodeList()
	 * @method \int[] fillOptionCode()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption $object)
	 * @method bool has(\Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption getByPrimary($primary)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection merge(?\Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption|null find(callable $callback)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection filter(callable $callback)
	 */
	class EO_DocumentTypeUserOption_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\DocumentType\DocumentTypeUserOptionTable */
		static public $dataClass = '\Bitrix\Bizproc\DocumentType\DocumentTypeUserOptionTable';
	}
}
namespace Bitrix\Bizproc\DocumentType {
	/**
	 * @method static EO_DocumentTypeUserOption_Query query()
	 * @method static EO_DocumentTypeUserOption_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DocumentTypeUserOption_Result getById($id)
	 * @method static EO_DocumentTypeUserOption_Result getList(array $parameters = [])
	 * @method static EO_DocumentTypeUserOption_Entity getEntity()
	 * @method static \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection createCollection()
	 * @method static \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection wakeUpCollection($rows)
	 */
	class DocumentTypeUserOptionTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DocumentTypeUserOption_Result exec()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption fetchObject()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection fetchCollection()
	 */
	class EO_DocumentTypeUserOption_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption fetchObject()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection fetchCollection()
	 */
	class EO_DocumentTypeUserOption_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection createCollection()
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption wakeUpObject($row)
	 * @method \Bitrix\Bizproc\DocumentType\EO_DocumentTypeUserOption_Collection wakeUpCollection($rows)
	 */
	class EO_DocumentTypeUserOption_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\RestActivityTable */
namespace Bitrix\Bizproc {
	/**
	 * EO_RestActivity
	 * @see \Bitrix\Bizproc\RestActivityTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\EO_RestActivity setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getAppId()
	 * @method \Bitrix\Bizproc\EO_RestActivity setAppId(\string|\Bitrix\Main\DB\SqlExpression $appId)
	 * @method bool hasAppId()
	 * @method bool isAppIdFilled()
	 * @method bool isAppIdChanged()
	 * @method \string remindActualAppId()
	 * @method \string requireAppId()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetAppId()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetAppId()
	 * @method \string fillAppId()
	 * @method \string getAppName()
	 * @method \Bitrix\Bizproc\EO_RestActivity setAppName(\string|\Bitrix\Main\DB\SqlExpression $appName)
	 * @method bool hasAppName()
	 * @method bool isAppNameFilled()
	 * @method bool isAppNameChanged()
	 * @method \string remindActualAppName()
	 * @method \string requireAppName()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetAppName()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetAppName()
	 * @method \string fillAppName()
	 * @method \string getCode()
	 * @method \Bitrix\Bizproc\EO_RestActivity setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetCode()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetCode()
	 * @method \string fillCode()
	 * @method \string getInternalCode()
	 * @method \Bitrix\Bizproc\EO_RestActivity setInternalCode(\string|\Bitrix\Main\DB\SqlExpression $internalCode)
	 * @method bool hasInternalCode()
	 * @method bool isInternalCodeFilled()
	 * @method bool isInternalCodeChanged()
	 * @method \string remindActualInternalCode()
	 * @method \string requireInternalCode()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetInternalCode()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetInternalCode()
	 * @method \string fillInternalCode()
	 * @method \string getHandler()
	 * @method \Bitrix\Bizproc\EO_RestActivity setHandler(\string|\Bitrix\Main\DB\SqlExpression $handler)
	 * @method bool hasHandler()
	 * @method bool isHandlerFilled()
	 * @method bool isHandlerChanged()
	 * @method \string remindActualHandler()
	 * @method \string requireHandler()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetHandler()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetHandler()
	 * @method \string fillHandler()
	 * @method \int getAuthUserId()
	 * @method \Bitrix\Bizproc\EO_RestActivity setAuthUserId(\int|\Bitrix\Main\DB\SqlExpression $authUserId)
	 * @method bool hasAuthUserId()
	 * @method bool isAuthUserIdFilled()
	 * @method bool isAuthUserIdChanged()
	 * @method \int remindActualAuthUserId()
	 * @method \int requireAuthUserId()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetAuthUserId()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetAuthUserId()
	 * @method \int fillAuthUserId()
	 * @method \string getUseSubscription()
	 * @method \Bitrix\Bizproc\EO_RestActivity setUseSubscription(\string|\Bitrix\Main\DB\SqlExpression $useSubscription)
	 * @method bool hasUseSubscription()
	 * @method bool isUseSubscriptionFilled()
	 * @method bool isUseSubscriptionChanged()
	 * @method \string remindActualUseSubscription()
	 * @method \string requireUseSubscription()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetUseSubscription()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetUseSubscription()
	 * @method \string fillUseSubscription()
	 * @method \boolean getUsePlacement()
	 * @method \Bitrix\Bizproc\EO_RestActivity setUsePlacement(\boolean|\Bitrix\Main\DB\SqlExpression $usePlacement)
	 * @method bool hasUsePlacement()
	 * @method bool isUsePlacementFilled()
	 * @method bool isUsePlacementChanged()
	 * @method \boolean remindActualUsePlacement()
	 * @method \boolean requireUsePlacement()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetUsePlacement()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetUsePlacement()
	 * @method \boolean fillUsePlacement()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\EO_RestActivity setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetName()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\EO_RestActivity setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetDescription()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetDescription()
	 * @method \string fillDescription()
	 * @method \string getProperties()
	 * @method \Bitrix\Bizproc\EO_RestActivity setProperties(\string|\Bitrix\Main\DB\SqlExpression $properties)
	 * @method bool hasProperties()
	 * @method bool isPropertiesFilled()
	 * @method bool isPropertiesChanged()
	 * @method \string remindActualProperties()
	 * @method \string requireProperties()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetProperties()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetProperties()
	 * @method \string fillProperties()
	 * @method \string getReturnProperties()
	 * @method \Bitrix\Bizproc\EO_RestActivity setReturnProperties(\string|\Bitrix\Main\DB\SqlExpression $returnProperties)
	 * @method bool hasReturnProperties()
	 * @method bool isReturnPropertiesFilled()
	 * @method bool isReturnPropertiesChanged()
	 * @method \string remindActualReturnProperties()
	 * @method \string requireReturnProperties()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetReturnProperties()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetReturnProperties()
	 * @method \string fillReturnProperties()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\EO_RestActivity setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetDocumentType()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \string getFilter()
	 * @method \Bitrix\Bizproc\EO_RestActivity setFilter(\string|\Bitrix\Main\DB\SqlExpression $filter)
	 * @method bool hasFilter()
	 * @method bool isFilterFilled()
	 * @method bool isFilterChanged()
	 * @method \string remindActualFilter()
	 * @method \string requireFilter()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetFilter()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetFilter()
	 * @method \string fillFilter()
	 * @method \boolean getIsRobot()
	 * @method \Bitrix\Bizproc\EO_RestActivity setIsRobot(\boolean|\Bitrix\Main\DB\SqlExpression $isRobot)
	 * @method bool hasIsRobot()
	 * @method bool isIsRobotFilled()
	 * @method bool isIsRobotChanged()
	 * @method \boolean remindActualIsRobot()
	 * @method \boolean requireIsRobot()
	 * @method \Bitrix\Bizproc\EO_RestActivity resetIsRobot()
	 * @method \Bitrix\Bizproc\EO_RestActivity unsetIsRobot()
	 * @method \boolean fillIsRobot()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\EO_RestActivity set($fieldName, $value)
	 * @method \Bitrix\Bizproc\EO_RestActivity reset($fieldName)
	 * @method \Bitrix\Bizproc\EO_RestActivity unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\EO_RestActivity wakeUp($data)
	 */
	class EO_RestActivity extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\RestActivityTable */
		static public $dataClass = '\Bitrix\Bizproc\RestActivityTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc {
	/**
	 * EO_RestActivity_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getAppIdList()
	 * @method \string[] fillAppId()
	 * @method \string[] getAppNameList()
	 * @method \string[] fillAppName()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getInternalCodeList()
	 * @method \string[] fillInternalCode()
	 * @method \string[] getHandlerList()
	 * @method \string[] fillHandler()
	 * @method \int[] getAuthUserIdList()
	 * @method \int[] fillAuthUserId()
	 * @method \string[] getUseSubscriptionList()
	 * @method \string[] fillUseSubscription()
	 * @method \boolean[] getUsePlacementList()
	 * @method \boolean[] fillUsePlacement()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \string[] getPropertiesList()
	 * @method \string[] fillProperties()
	 * @method \string[] getReturnPropertiesList()
	 * @method \string[] fillReturnProperties()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \string[] getFilterList()
	 * @method \string[] fillFilter()
	 * @method \boolean[] getIsRobotList()
	 * @method \boolean[] fillIsRobot()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\EO_RestActivity $object)
	 * @method bool has(\Bitrix\Bizproc\EO_RestActivity $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\EO_RestActivity getByPrimary($primary)
	 * @method \Bitrix\Bizproc\EO_RestActivity[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\EO_RestActivity $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\EO_RestActivity_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\EO_RestActivity current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\EO_RestActivity_Collection merge(?\Bitrix\Bizproc\EO_RestActivity_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\EO_RestActivity|null find(callable $callback)
	 * @method \Bitrix\Bizproc\EO_RestActivity_Collection filter(callable $callback)
	 */
	class EO_RestActivity_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\RestActivityTable */
		static public $dataClass = '\Bitrix\Bizproc\RestActivityTable';
	}
}
namespace Bitrix\Bizproc {
	/**
	 * @method static EO_RestActivity_Query query()
	 * @method static EO_RestActivity_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_RestActivity_Result getById($id)
	 * @method static EO_RestActivity_Result getList(array $parameters = [])
	 * @method static EO_RestActivity_Entity getEntity()
	 * @method static \Bitrix\Bizproc\EO_RestActivity createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\EO_RestActivity_Collection createCollection()
	 * @method static \Bitrix\Bizproc\EO_RestActivity wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\EO_RestActivity_Collection wakeUpCollection($rows)
	 */
	class RestActivityTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RestActivity_Result exec()
	 * @method \Bitrix\Bizproc\EO_RestActivity fetchObject()
	 * @method \Bitrix\Bizproc\EO_RestActivity_Collection fetchCollection()
	 */
	class EO_RestActivity_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\EO_RestActivity fetchObject()
	 * @method \Bitrix\Bizproc\EO_RestActivity_Collection fetchCollection()
	 */
	class EO_RestActivity_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\EO_RestActivity createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\EO_RestActivity_Collection createCollection()
	 * @method \Bitrix\Bizproc\EO_RestActivity wakeUpObject($row)
	 * @method \Bitrix\Bizproc\EO_RestActivity_Collection wakeUpCollection($rows)
	 */
	class EO_RestActivity_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionWorkflowContextTable */
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * WorkflowContext
	 * @see \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionWorkflowContextTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext setSessionId(\string|\Bitrix\Main\DB\SqlExpression $sessionId)
	 * @method bool hasSessionId()
	 * @method bool isSessionIdFilled()
	 * @method bool isSessionIdChanged()
	 * @method \string remindActualSessionId()
	 * @method \string requireSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext resetSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext unsetSessionId()
	 * @method \string fillSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session getSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session remindActualSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session requireSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext setSession(\Bitrix\Bizproc\Debugger\Session\Session $object)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext resetSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext unsetSession()
	 * @method bool hasSession()
	 * @method bool isSessionFilled()
	 * @method bool isSessionChanged()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session fillSession()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext resetWorkflowId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getTemplateShardsId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext setTemplateShardsId(\int|\Bitrix\Main\DB\SqlExpression $templateShardsId)
	 * @method bool hasTemplateShardsId()
	 * @method bool isTemplateShardsIdFilled()
	 * @method bool isTemplateShardsIdChanged()
	 * @method \int remindActualTemplateShardsId()
	 * @method \int requireTemplateShardsId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext resetTemplateShardsId()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext unsetTemplateShardsId()
	 * @method \int fillTemplateShardsId()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards getTemplateShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards remindActualTemplateShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards requireTemplateShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext setTemplateShards(\Bitrix\Bizproc\Debugger\Session\TemplateShards $object)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext resetTemplateShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext unsetTemplateShards()
	 * @method bool hasTemplateShards()
	 * @method bool isTemplateShardsFilled()
	 * @method bool isTemplateShardsChanged()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards fillTemplateShards()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext reset($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Debugger\Session\WorkflowContext wakeUp($data)
	 */
	class EO_DebuggerSessionWorkflowContext extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionWorkflowContextTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionWorkflowContextTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * EO_DebuggerSessionWorkflowContext_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getSessionIdList()
	 * @method \string[] fillSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session[] getSessionList()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection getSessionCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection fillSession()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getTemplateShardsIdList()
	 * @method \int[] fillTemplateShardsId()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards[] getTemplateShardsList()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection getTemplateShardsCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection fillTemplateShards()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Debugger\Session\WorkflowContext $object)
	 * @method bool has(\Bitrix\Bizproc\Debugger\Session\WorkflowContext $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Debugger\Session\WorkflowContext $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection merge(?\Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection filter(callable $callback)
	 */
	class EO_DebuggerSessionWorkflowContext_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionWorkflowContextTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionWorkflowContextTable';
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * @method static EO_DebuggerSessionWorkflowContext_Query query()
	 * @method static EO_DebuggerSessionWorkflowContext_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DebuggerSessionWorkflowContext_Result getById($id)
	 * @method static EO_DebuggerSessionWorkflowContext_Result getList(array $parameters = [])
	 * @method static EO_DebuggerSessionWorkflowContext_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Debugger\Session\WorkflowContext createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Debugger\Session\WorkflowContext wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection wakeUpCollection($rows)
	 */
	class DebuggerSessionWorkflowContextTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DebuggerSessionWorkflowContext_Result exec()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection fetchCollection()
	 */
	class EO_DebuggerSessionWorkflowContext_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection fetchCollection()
	 */
	class EO_DebuggerSessionWorkflowContext_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection createCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\WorkflowContext wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection wakeUpCollection($rows)
	 */
	class EO_DebuggerSessionWorkflowContext_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionDocumentTable */
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * Document
	 * @see \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionDocumentTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document setSessionId(\string|\Bitrix\Main\DB\SqlExpression $sessionId)
	 * @method bool hasSessionId()
	 * @method bool isSessionIdFilled()
	 * @method bool isSessionIdChanged()
	 * @method \string remindActualSessionId()
	 * @method \string requireSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document resetSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document unsetSessionId()
	 * @method \string fillSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session getSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session remindActualSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session requireSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document setSession(\Bitrix\Bizproc\Debugger\Session\Session $object)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document resetSession()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document unsetSession()
	 * @method bool hasSession()
	 * @method bool isSessionFilled()
	 * @method bool isSessionChanged()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session fillSession()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document resetDocumentId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \Bitrix\Main\Type\DateTime getDateExpire()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document setDateExpire(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateExpire)
	 * @method bool hasDateExpire()
	 * @method bool isDateExpireFilled()
	 * @method bool isDateExpireChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateExpire()
	 * @method \Bitrix\Main\Type\DateTime requireDateExpire()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document resetDateExpire()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document unsetDateExpire()
	 * @method \Bitrix\Main\Type\DateTime fillDateExpire()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document reset($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Debugger\Session\Document wakeUp($data)
	 */
	class EO_DebuggerSessionDocument extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionDocumentTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionDocumentTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * EO_DebuggerSessionDocument_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getSessionIdList()
	 * @method \string[] fillSessionId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session[] getSessionList()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection getSessionCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection fillSession()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \Bitrix\Main\Type\DateTime[] getDateExpireList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateExpire()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Debugger\Session\Document $object)
	 * @method bool has(\Bitrix\Bizproc\Debugger\Session\Document $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Debugger\Session\Document $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Debugger\Session\Document current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection merge(?\Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Debugger\Session\Document|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection filter(callable $callback)
	 */
	class EO_DebuggerSessionDocument_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionDocumentTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionDocumentTable';
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * @method static EO_DebuggerSessionDocument_Query query()
	 * @method static EO_DebuggerSessionDocument_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DebuggerSessionDocument_Result getById($id)
	 * @method static EO_DebuggerSessionDocument_Result getList(array $parameters = [])
	 * @method static EO_DebuggerSessionDocument_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Debugger\Session\Document createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Debugger\Session\Document wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection wakeUpCollection($rows)
	 */
	class DebuggerSessionDocumentTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DebuggerSessionDocument_Result exec()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection fetchCollection()
	 */
	class EO_DebuggerSessionDocument_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\Document fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection fetchCollection()
	 */
	class EO_DebuggerSessionDocument_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\Document createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection createCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Document wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection wakeUpCollection($rows)
	 */
	class EO_DebuggerSessionDocument_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTable */
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * Session
	 * @see \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setId(\string|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetModuleId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetEntity()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetDocumentType()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \int getDocumentCategoryId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setDocumentCategoryId(\int|\Bitrix\Main\DB\SqlExpression $documentCategoryId)
	 * @method bool hasDocumentCategoryId()
	 * @method bool isDocumentCategoryIdFilled()
	 * @method bool isDocumentCategoryIdChanged()
	 * @method \int remindActualDocumentCategoryId()
	 * @method \int requireDocumentCategoryId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetDocumentCategoryId()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetDocumentCategoryId()
	 * @method \int fillDocumentCategoryId()
	 * @method \int getMode()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setMode(\int|\Bitrix\Main\DB\SqlExpression $mode)
	 * @method bool hasMode()
	 * @method bool isModeFilled()
	 * @method bool isModeChanged()
	 * @method \int remindActualMode()
	 * @method \int requireMode()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetMode()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetMode()
	 * @method \int fillMode()
	 * @method \string getTitle()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setTitle(\string|\Bitrix\Main\DB\SqlExpression $title)
	 * @method bool hasTitle()
	 * @method bool isTitleFilled()
	 * @method bool isTitleChanged()
	 * @method \string remindActualTitle()
	 * @method \string requireTitle()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetTitle()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetTitle()
	 * @method \string fillTitle()
	 * @method \int getStartedBy()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setStartedBy(\int|\Bitrix\Main\DB\SqlExpression $startedBy)
	 * @method bool hasStartedBy()
	 * @method bool isStartedByFilled()
	 * @method bool isStartedByChanged()
	 * @method \int remindActualStartedBy()
	 * @method \int requireStartedBy()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetStartedBy()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetStartedBy()
	 * @method \int fillStartedBy()
	 * @method \Bitrix\Main\Type\DateTime getStartedDate()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setStartedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $startedDate)
	 * @method bool hasStartedDate()
	 * @method bool isStartedDateFilled()
	 * @method bool isStartedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStartedDate()
	 * @method \Bitrix\Main\Type\DateTime requireStartedDate()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetStartedDate()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetStartedDate()
	 * @method \Bitrix\Main\Type\DateTime fillStartedDate()
	 * @method \Bitrix\Main\Type\DateTime getFinishedDate()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setFinishedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $finishedDate)
	 * @method bool hasFinishedDate()
	 * @method bool isFinishedDateFilled()
	 * @method bool isFinishedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualFinishedDate()
	 * @method \Bitrix\Main\Type\DateTime requireFinishedDate()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetFinishedDate()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetFinishedDate()
	 * @method \Bitrix\Main\Type\DateTime fillFinishedDate()
	 * @method \boolean getActive()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setActive(\boolean|\Bitrix\Main\DB\SqlExpression $active)
	 * @method bool hasActive()
	 * @method bool isActiveFilled()
	 * @method bool isActiveChanged()
	 * @method \boolean remindActualActive()
	 * @method \boolean requireActive()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetActive()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetActive()
	 * @method \boolean fillActive()
	 * @method \boolean getFixed()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setFixed(\boolean|\Bitrix\Main\DB\SqlExpression $fixed)
	 * @method bool hasFixed()
	 * @method bool isFixedFilled()
	 * @method bool isFixedChanged()
	 * @method \boolean remindActualFixed()
	 * @method \boolean requireFixed()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetFixed()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetFixed()
	 * @method \boolean fillFixed()
	 * @method \int getDebuggerState()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session setDebuggerState(\int|\Bitrix\Main\DB\SqlExpression $debuggerState)
	 * @method bool hasDebuggerState()
	 * @method bool isDebuggerStateFilled()
	 * @method bool isDebuggerStateChanged()
	 * @method \int remindActualDebuggerState()
	 * @method \int requireDebuggerState()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetDebuggerState()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetDebuggerState()
	 * @method \int fillDebuggerState()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection getDocuments()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection requireDocuments()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection fillDocuments()
	 * @method bool hasDocuments()
	 * @method bool isDocumentsFilled()
	 * @method bool isDocumentsChanged()
	 * @method void addToDocuments(\Bitrix\Bizproc\Debugger\Session\Document $debuggerSessionDocument)
	 * @method void removeFromDocuments(\Bitrix\Bizproc\Debugger\Session\Document $debuggerSessionDocument)
	 * @method void removeAllDocuments()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetDocuments()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetDocuments()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection getWorkflowContexts()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection requireWorkflowContexts()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection fillWorkflowContexts()
	 * @method bool hasWorkflowContexts()
	 * @method bool isWorkflowContextsFilled()
	 * @method bool isWorkflowContextsChanged()
	 * @method void addToWorkflowContexts(\Bitrix\Bizproc\Debugger\Session\WorkflowContext $debuggerSessionWorkflowContext)
	 * @method void removeFromWorkflowContexts(\Bitrix\Bizproc\Debugger\Session\WorkflowContext $debuggerSessionWorkflowContext)
	 * @method void removeAllWorkflowContexts()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session resetWorkflowContexts()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unsetWorkflowContexts()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\Session set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Debugger\Session\Session reset($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\Session unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Debugger\Session\Session wakeUp($data)
	 */
	class EO_DebuggerSession extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * EO_DebuggerSession_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \int[] getDocumentCategoryIdList()
	 * @method \int[] fillDocumentCategoryId()
	 * @method \int[] getModeList()
	 * @method \int[] fillMode()
	 * @method \string[] getTitleList()
	 * @method \string[] fillTitle()
	 * @method \int[] getStartedByList()
	 * @method \int[] fillStartedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getStartedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStartedDate()
	 * @method \Bitrix\Main\Type\DateTime[] getFinishedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillFinishedDate()
	 * @method \boolean[] getActiveList()
	 * @method \boolean[] fillActive()
	 * @method \boolean[] getFixedList()
	 * @method \boolean[] fillFixed()
	 * @method \int[] getDebuggerStateList()
	 * @method \int[] fillDebuggerState()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection[] getDocumentsList()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection getDocumentsCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionDocument_Collection fillDocuments()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection[] getWorkflowContextsList()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection getWorkflowContextsCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionWorkflowContext_Collection fillWorkflowContexts()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Debugger\Session\Session $object)
	 * @method bool has(\Bitrix\Bizproc\Debugger\Session\Session $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\Session getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\Session[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Debugger\Session\Session $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Debugger\Session\Session current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection merge(?\Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Debugger\Session\Session|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection filter(callable $callback)
	 */
	class EO_DebuggerSession_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTable';
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * @method static EO_DebuggerSession_Query query()
	 * @method static EO_DebuggerSession_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DebuggerSession_Result getById($id)
	 * @method static EO_DebuggerSession_Result getList(array $parameters = [])
	 * @method static EO_DebuggerSession_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Debugger\Session\Session createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Debugger\Session\Session wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection wakeUpCollection($rows)
	 */
	class DebuggerSessionTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DebuggerSession_Result exec()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection fetchCollection()
	 */
	class EO_DebuggerSession_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\Session fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection fetchCollection()
	 */
	class EO_DebuggerSession_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\Session createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection createCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\Session wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSession_Collection wakeUpCollection($rows)
	 */
	class EO_DebuggerSession_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTemplateShardsTable */
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * TemplateShards
	 * @see \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTemplateShardsTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards resetTemplateId()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards resetTemplate()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 * @method array getShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards setShards(array|\Bitrix\Main\DB\SqlExpression $shards)
	 * @method bool hasShards()
	 * @method bool isShardsFilled()
	 * @method bool isShardsChanged()
	 * @method array remindActualShards()
	 * @method array requireShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards resetShards()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards unsetShards()
	 * @method array fillShards()
	 * @method \string getTemplateType()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards setTemplateType(\string|\Bitrix\Main\DB\SqlExpression $templateType)
	 * @method bool hasTemplateType()
	 * @method bool isTemplateTypeFilled()
	 * @method bool isTemplateTypeChanged()
	 * @method \string remindActualTemplateType()
	 * @method \string requireTemplateType()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards resetTemplateType()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards unsetTemplateType()
	 * @method \string fillTemplateType()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards resetModified()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards reset($fieldName)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Debugger\Session\TemplateShards wakeUp($data)
	 */
	class EO_DebuggerSessionTemplateShards extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTemplateShardsTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTemplateShardsTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * EO_DebuggerSessionTemplateShards_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 * @method array[] getShardsList()
	 * @method array[] fillShards()
	 * @method \string[] getTemplateTypeList()
	 * @method \string[] fillTemplateType()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Debugger\Session\TemplateShards $object)
	 * @method bool has(\Bitrix\Bizproc\Debugger\Session\TemplateShards $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Debugger\Session\TemplateShards $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection merge(?\Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection filter(callable $callback)
	 */
	class EO_DebuggerSessionTemplateShards_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTemplateShardsTable */
		static public $dataClass = '\Bitrix\Bizproc\Debugger\Session\Entity\DebuggerSessionTemplateShardsTable';
	}
}
namespace Bitrix\Bizproc\Debugger\Session\Entity {
	/**
	 * @method static EO_DebuggerSessionTemplateShards_Query query()
	 * @method static EO_DebuggerSessionTemplateShards_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_DebuggerSessionTemplateShards_Result getById($id)
	 * @method static EO_DebuggerSessionTemplateShards_Result getList(array $parameters = [])
	 * @method static EO_DebuggerSessionTemplateShards_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Debugger\Session\TemplateShards createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Debugger\Session\TemplateShards wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection wakeUpCollection($rows)
	 */
	class DebuggerSessionTemplateShardsTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DebuggerSessionTemplateShards_Result exec()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection fetchCollection()
	 */
	class EO_DebuggerSessionTemplateShards_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards fetchObject()
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection fetchCollection()
	 */
	class EO_DebuggerSessionTemplateShards_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection createCollection()
	 * @method \Bitrix\Bizproc\Debugger\Session\TemplateShards wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Debugger\Session\Entity\EO_DebuggerSessionTemplateShards_Collection wakeUpCollection($rows)
	 */
	class EO_DebuggerSessionTemplateShards_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Result\Entity\ResultTable */
namespace Bitrix\Bizproc\Result\Entity {
	/**
	 * EO_Result
	 * @see \Bitrix\Bizproc\Result\Entity\ResultTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result resetWorkflowId()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \string getActivity()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result setActivity(\string|\Bitrix\Main\DB\SqlExpression $activity)
	 * @method bool hasActivity()
	 * @method bool isActivityFilled()
	 * @method bool isActivityChanged()
	 * @method \string remindActualActivity()
	 * @method \string requireActivity()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result resetActivity()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result unsetActivity()
	 * @method \string fillActivity()
	 * @method array getResult()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result setResult(array|\Bitrix\Main\DB\SqlExpression $result)
	 * @method bool hasResult()
	 * @method bool isResultFilled()
	 * @method bool isResultChanged()
	 * @method array remindActualResult()
	 * @method array requireResult()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result resetResult()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result unsetResult()
	 * @method array fillResult()
	 * @method \Bitrix\Main\Type\DateTime getCreatedDate()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result setCreatedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdDate)
	 * @method bool hasCreatedDate()
	 * @method bool isCreatedDateFilled()
	 * @method bool isCreatedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedDate()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result resetCreatedDate()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result unsetCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedDate()
	 * @method \int getPriority()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result setPriority(\int|\Bitrix\Main\DB\SqlExpression $priority)
	 * @method bool hasPriority()
	 * @method bool isPriorityFilled()
	 * @method bool isPriorityChanged()
	 * @method \int remindActualPriority()
	 * @method \int requirePriority()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result resetPriority()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result unsetPriority()
	 * @method \int fillPriority()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result reset($fieldName)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Result\Entity\EO_Result wakeUp($data)
	 */
	class EO_Result extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Result\Entity\ResultTable */
		static public $dataClass = '\Bitrix\Bizproc\Result\Entity\ResultTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Result\Entity {
	/**
	 * EO_Result_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \string[] getActivityList()
	 * @method \string[] fillActivity()
	 * @method array[] getResultList()
	 * @method array[] fillResult()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedDate()
	 * @method \int[] getPriorityList()
	 * @method \int[] fillPriority()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Result\Entity\EO_Result $object)
	 * @method bool has(\Bitrix\Bizproc\Result\Entity\EO_Result $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Result\Entity\EO_Result $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Result\Entity\EO_Result_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result_Collection merge(?\Bitrix\Bizproc\Result\Entity\EO_Result_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result_Collection filter(callable $callback)
	 */
	class EO_Result_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Result\Entity\ResultTable */
		static public $dataClass = '\Bitrix\Bizproc\Result\Entity\ResultTable';
	}
}
namespace Bitrix\Bizproc\Result\Entity {
	/**
	 * @method static EO_Result_Query query()
	 * @method static EO_Result_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Result_Result getById($id)
	 * @method static EO_Result_Result getList(array $parameters = [])
	 * @method static EO_Result_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Result\Entity\EO_Result createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Result\Entity\EO_Result_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Result\Entity\EO_Result wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Result\Entity\EO_Result_Collection wakeUpCollection($rows)
	 */
	class ResultTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Result_Result exec()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result fetchObject()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result_Collection fetchCollection()
	 */
	class EO_Result_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result fetchObject()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result_Collection fetchCollection()
	 */
	class EO_Result_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result_Collection createCollection()
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Result\Entity\EO_Result_Collection wakeUpCollection($rows)
	 */
	class EO_Result_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Service\Entity\TrackingTable */
namespace Bitrix\Bizproc\Service\Entity {
	/**
	 * EO_Tracking
	 * @see \Bitrix\Bizproc\Service\Entity\TrackingTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetWorkflowId()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getType()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setType(\int|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \int remindActualType()
	 * @method \int requireType()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetType()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetType()
	 * @method \int fillType()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetModified()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method \string getActionName()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setActionName(\string|\Bitrix\Main\DB\SqlExpression $actionName)
	 * @method bool hasActionName()
	 * @method bool isActionNameFilled()
	 * @method bool isActionNameChanged()
	 * @method \string remindActualActionName()
	 * @method \string requireActionName()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetActionName()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetActionName()
	 * @method \string fillActionName()
	 * @method \string getActionTitle()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setActionTitle(\string|\Bitrix\Main\DB\SqlExpression $actionTitle)
	 * @method bool hasActionTitle()
	 * @method bool isActionTitleFilled()
	 * @method bool isActionTitleChanged()
	 * @method \string remindActualActionTitle()
	 * @method \string requireActionTitle()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetActionTitle()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetActionTitle()
	 * @method \string fillActionTitle()
	 * @method \int getExecutionStatus()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setExecutionStatus(\int|\Bitrix\Main\DB\SqlExpression $executionStatus)
	 * @method bool hasExecutionStatus()
	 * @method bool isExecutionStatusFilled()
	 * @method bool isExecutionStatusChanged()
	 * @method \int remindActualExecutionStatus()
	 * @method \int requireExecutionStatus()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetExecutionStatus()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetExecutionStatus()
	 * @method \int fillExecutionStatus()
	 * @method \int getExecutionResult()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setExecutionResult(\int|\Bitrix\Main\DB\SqlExpression $executionResult)
	 * @method bool hasExecutionResult()
	 * @method bool isExecutionResultFilled()
	 * @method bool isExecutionResultChanged()
	 * @method \int remindActualExecutionResult()
	 * @method \int requireExecutionResult()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetExecutionResult()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetExecutionResult()
	 * @method \int fillExecutionResult()
	 * @method null|\string getActionNote()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setActionNote(null|\string|\Bitrix\Main\DB\SqlExpression $actionNote)
	 * @method bool hasActionNote()
	 * @method bool isActionNoteFilled()
	 * @method bool isActionNoteChanged()
	 * @method null|\string remindActualActionNote()
	 * @method null|\string requireActionNote()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetActionNote()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetActionNote()
	 * @method null|\string fillActionNote()
	 * @method null|\int getModifiedBy()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setModifiedBy(null|\int|\Bitrix\Main\DB\SqlExpression $modifiedBy)
	 * @method bool hasModifiedBy()
	 * @method bool isModifiedByFilled()
	 * @method bool isModifiedByChanged()
	 * @method null|\int remindActualModifiedBy()
	 * @method null|\int requireModifiedBy()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetModifiedBy()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetModifiedBy()
	 * @method null|\int fillModifiedBy()
	 * @method \string getCompleted()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking setCompleted(\string|\Bitrix\Main\DB\SqlExpression $completed)
	 * @method bool hasCompleted()
	 * @method bool isCompletedFilled()
	 * @method bool isCompletedChanged()
	 * @method \string remindActualCompleted()
	 * @method \string requireCompleted()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking resetCompleted()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unsetCompleted()
	 * @method \string fillCompleted()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking reset($fieldName)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Service\Entity\EO_Tracking wakeUp($data)
	 */
	class EO_Tracking extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Service\Entity\TrackingTable */
		static public $dataClass = '\Bitrix\Bizproc\Service\Entity\TrackingTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Service\Entity {
	/**
	 * EO_Tracking_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getTypeList()
	 * @method \int[] fillType()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \string[] getActionNameList()
	 * @method \string[] fillActionName()
	 * @method \string[] getActionTitleList()
	 * @method \string[] fillActionTitle()
	 * @method \int[] getExecutionStatusList()
	 * @method \int[] fillExecutionStatus()
	 * @method \int[] getExecutionResultList()
	 * @method \int[] fillExecutionResult()
	 * @method null|\string[] getActionNoteList()
	 * @method null|\string[] fillActionNote()
	 * @method null|\int[] getModifiedByList()
	 * @method null|\int[] fillModifiedBy()
	 * @method \string[] getCompletedList()
	 * @method \string[] fillCompleted()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Service\Entity\EO_Tracking $object)
	 * @method bool has(\Bitrix\Bizproc\Service\Entity\EO_Tracking $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Service\Entity\EO_Tracking $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection merge(?\Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection filter(callable $callback)
	 */
	class EO_Tracking_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Service\Entity\TrackingTable */
		static public $dataClass = '\Bitrix\Bizproc\Service\Entity\TrackingTable';
	}
}
namespace Bitrix\Bizproc\Service\Entity {
	/**
	 * @method static EO_Tracking_Query query()
	 * @method static EO_Tracking_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Tracking_Result getById($id)
	 * @method static EO_Tracking_Result getList(array $parameters = [])
	 * @method static EO_Tracking_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Service\Entity\EO_Tracking createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Service\Entity\EO_Tracking wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection wakeUpCollection($rows)
	 */
	class TrackingTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Tracking_Result exec()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking fetchObject()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection fetchCollection()
	 */
	class EO_Tracking_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking fetchObject()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection fetchCollection()
	 */
	class EO_Tracking_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection createCollection()
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Service\Entity\EO_Tracking_Collection wakeUpCollection($rows)
	 */
	class EO_Tracking_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Task\TaskTable */
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * Task
	 * @see \Bitrix\Bizproc\Workflow\Task\TaskTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Task setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Task setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Task resetWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \string getActivity()
	 * @method \Bitrix\Bizproc\Workflow\Task setActivity(\string|\Bitrix\Main\DB\SqlExpression $activity)
	 * @method bool hasActivity()
	 * @method bool isActivityFilled()
	 * @method bool isActivityChanged()
	 * @method \string remindActualActivity()
	 * @method \string requireActivity()
	 * @method \Bitrix\Bizproc\Workflow\Task resetActivity()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetActivity()
	 * @method \string fillActivity()
	 * @method \string getActivityName()
	 * @method \Bitrix\Bizproc\Workflow\Task setActivityName(\string|\Bitrix\Main\DB\SqlExpression $activityName)
	 * @method bool hasActivityName()
	 * @method bool isActivityNameFilled()
	 * @method bool isActivityNameChanged()
	 * @method \string remindActualActivityName()
	 * @method \string requireActivityName()
	 * @method \Bitrix\Bizproc\Workflow\Task resetActivityName()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetActivityName()
	 * @method \string fillActivityName()
	 * @method null|\Bitrix\Main\Type\DateTime getCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Task setCreatedDate(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdDate)
	 * @method bool hasCreatedDate()
	 * @method bool isCreatedDateFilled()
	 * @method bool isCreatedDateChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualCreatedDate()
	 * @method null|\Bitrix\Main\Type\DateTime requireCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Task resetCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetCreatedDate()
	 * @method null|\Bitrix\Main\Type\DateTime fillCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Task setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Task resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method null|\Bitrix\Main\Type\DateTime getOverdueDate()
	 * @method \Bitrix\Bizproc\Workflow\Task setOverdueDate(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $overdueDate)
	 * @method bool hasOverdueDate()
	 * @method bool isOverdueDateFilled()
	 * @method bool isOverdueDateChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualOverdueDate()
	 * @method null|\Bitrix\Main\Type\DateTime requireOverdueDate()
	 * @method \Bitrix\Bizproc\Workflow\Task resetOverdueDate()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetOverdueDate()
	 * @method null|\Bitrix\Main\Type\DateTime fillOverdueDate()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Task setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Task resetName()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetName()
	 * @method \string fillName()
	 * @method null|\string getDescription()
	 * @method \Bitrix\Bizproc\Workflow\Task setDescription(null|\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method null|\string remindActualDescription()
	 * @method null|\string requireDescription()
	 * @method \Bitrix\Bizproc\Workflow\Task resetDescription()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetDescription()
	 * @method null|\string fillDescription()
	 * @method array getParameters()
	 * @method \Bitrix\Bizproc\Workflow\Task setParameters(array|\Bitrix\Main\DB\SqlExpression $parameters)
	 * @method bool hasParameters()
	 * @method bool isParametersFilled()
	 * @method bool isParametersChanged()
	 * @method array remindActualParameters()
	 * @method array requireParameters()
	 * @method \Bitrix\Bizproc\Workflow\Task resetParameters()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetParameters()
	 * @method array fillParameters()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Workflow\Task setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Workflow\Task resetStatus()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetStatus()
	 * @method \int fillStatus()
	 * @method \string getIsInline()
	 * @method \Bitrix\Bizproc\Workflow\Task setIsInline(\string|\Bitrix\Main\DB\SqlExpression $isInline)
	 * @method bool hasIsInline()
	 * @method bool isIsInlineFilled()
	 * @method bool isIsInlineChanged()
	 * @method \string remindActualIsInline()
	 * @method \string requireIsInline()
	 * @method \Bitrix\Bizproc\Workflow\Task resetIsInline()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetIsInline()
	 * @method \string fillIsInline()
	 * @method \int getDelegationType()
	 * @method \Bitrix\Bizproc\Workflow\Task setDelegationType(\int|\Bitrix\Main\DB\SqlExpression $delegationType)
	 * @method bool hasDelegationType()
	 * @method bool isDelegationTypeFilled()
	 * @method bool isDelegationTypeChanged()
	 * @method \int remindActualDelegationType()
	 * @method \int requireDelegationType()
	 * @method \Bitrix\Bizproc\Workflow\Task resetDelegationType()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetDelegationType()
	 * @method \int fillDelegationType()
	 * @method \string getDocumentName()
	 * @method \Bitrix\Bizproc\Workflow\Task setDocumentName(\string|\Bitrix\Main\DB\SqlExpression $documentName)
	 * @method bool hasDocumentName()
	 * @method bool isDocumentNameFilled()
	 * @method bool isDocumentNameChanged()
	 * @method \string remindActualDocumentName()
	 * @method \string requireDocumentName()
	 * @method \Bitrix\Bizproc\Workflow\Task resetDocumentName()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetDocumentName()
	 * @method \string fillDocumentName()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState getWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState remindActualWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState requireWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Task setWorkflowState(\Bitrix\Bizproc\Workflow\WorkflowState $object)
	 * @method \Bitrix\Bizproc\Workflow\Task resetWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetWorkflowState()
	 * @method bool hasWorkflowState()
	 * @method bool isWorkflowStateFilled()
	 * @method bool isWorkflowStateChanged()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState fillWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection getTaskUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection requireTaskUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection fillTaskUsers()
	 * @method bool hasTaskUsers()
	 * @method bool isTaskUsersFilled()
	 * @method bool isTaskUsersChanged()
	 * @method void addToTaskUsers(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $taskUser)
	 * @method void removeFromTaskUsers(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $taskUser)
	 * @method void removeAllTaskUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task resetTaskUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetTaskUsers()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance getWorkflowInstance()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance remindActualWorkflowInstance()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance requireWorkflowInstance()
	 * @method \Bitrix\Bizproc\Workflow\Task setWorkflowInstance(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance $object)
	 * @method \Bitrix\Bizproc\Workflow\Task resetWorkflowInstance()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetWorkflowInstance()
	 * @method bool hasWorkflowInstance()
	 * @method bool isWorkflowInstanceFilled()
	 * @method bool isWorkflowInstanceChanged()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance fillWorkflowInstance()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks getTaskArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks remindActualTaskArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks requireTaskArchive()
	 * @method \Bitrix\Bizproc\Workflow\Task setTaskArchive(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks $object)
	 * @method \Bitrix\Bizproc\Workflow\Task resetTaskArchive()
	 * @method \Bitrix\Bizproc\Workflow\Task unsetTaskArchive()
	 * @method bool hasTaskArchive()
	 * @method bool isTaskArchiveFilled()
	 * @method bool isTaskArchiveChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks fillTaskArchive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Task set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Task reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Task unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Task wakeUp($data)
	 */
	class EO_Task extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Task\TaskTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Task\TaskTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * EO_Task_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \string[] getActivityList()
	 * @method \string[] fillActivity()
	 * @method \string[] getActivityNameList()
	 * @method \string[] fillActivityName()
	 * @method null|\Bitrix\Main\Type\DateTime[] getCreatedDateList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method null|\Bitrix\Main\Type\DateTime[] getOverdueDateList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillOverdueDate()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method null|\string[] getDescriptionList()
	 * @method null|\string[] fillDescription()
	 * @method array[] getParametersList()
	 * @method array[] fillParameters()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \string[] getIsInlineList()
	 * @method \string[] fillIsInline()
	 * @method \int[] getDelegationTypeList()
	 * @method \int[] fillDelegationType()
	 * @method \string[] getDocumentNameList()
	 * @method \string[] fillDocumentName()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState[] getWorkflowStateList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection getWorkflowStateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection fillWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection[] getTaskUsersList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection getTaskUsersCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection fillTaskUsers()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance[] getWorkflowInstanceList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection getWorkflowInstanceCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection fillWorkflowInstance()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks[] getTaskArchiveList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection getTaskArchiveCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection fillTaskArchive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Task $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Task $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Task getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Task[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Task $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Task current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection merge(?\Bitrix\Bizproc\Workflow\Task\EO_Task_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Task|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection filter(callable $callback)
	 */
	class EO_Task_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Task\TaskTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Task\TaskTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * @method static EO_Task_Query query()
	 * @method static EO_Task_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_Task_Result getById($id)
	 * @method static EO_Task_Result getList(array $parameters = [])
	 * @method static EO_Task_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Task createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Task wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection wakeUpCollection($rows)
	 */
	class TaskTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Task_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Task fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection fetchCollection()
	 */
	class EO_Task_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Task fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection fetchCollection()
	 */
	class EO_Task_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Task createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection wakeUpCollection($rows)
	 */
	class EO_Task_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * WorkflowState
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setId(\string|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetModuleId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetEntity()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \int getDocumentIdInt()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setDocumentIdInt(\int|\Bitrix\Main\DB\SqlExpression $documentIdInt)
	 * @method bool hasDocumentIdInt()
	 * @method bool isDocumentIdIntFilled()
	 * @method bool isDocumentIdIntChanged()
	 * @method \int remindActualDocumentIdInt()
	 * @method \int requireDocumentIdInt()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetDocumentIdInt()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetDocumentIdInt()
	 * @method \int fillDocumentIdInt()
	 * @method \int getWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setWorkflowTemplateId(\int|\Bitrix\Main\DB\SqlExpression $workflowTemplateId)
	 * @method bool hasWorkflowTemplateId()
	 * @method bool isWorkflowTemplateIdFilled()
	 * @method bool isWorkflowTemplateIdChanged()
	 * @method \int remindActualWorkflowTemplateId()
	 * @method \int requireWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetWorkflowTemplateId()
	 * @method \int fillWorkflowTemplateId()
	 * @method \string getState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setState(\string|\Bitrix\Main\DB\SqlExpression $state)
	 * @method bool hasState()
	 * @method bool isStateFilled()
	 * @method bool isStateChanged()
	 * @method \string remindActualState()
	 * @method \string requireState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetState()
	 * @method \string fillState()
	 * @method \string getStateTitle()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setStateTitle(\string|\Bitrix\Main\DB\SqlExpression $stateTitle)
	 * @method bool hasStateTitle()
	 * @method bool isStateTitleFilled()
	 * @method bool isStateTitleChanged()
	 * @method \string remindActualStateTitle()
	 * @method \string requireStateTitle()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetStateTitle()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetStateTitle()
	 * @method \string fillStateTitle()
	 * @method \string getStateParameters()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setStateParameters(\string|\Bitrix\Main\DB\SqlExpression $stateParameters)
	 * @method bool hasStateParameters()
	 * @method bool isStateParametersFilled()
	 * @method bool isStateParametersChanged()
	 * @method \string remindActualStateParameters()
	 * @method \string requireStateParameters()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetStateParameters()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetStateParameters()
	 * @method \string fillStateParameters()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetModified()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method \Bitrix\Main\Type\DateTime getStarted()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setStarted(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $started)
	 * @method bool hasStarted()
	 * @method bool isStartedFilled()
	 * @method bool isStartedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStarted()
	 * @method \Bitrix\Main\Type\DateTime requireStarted()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetStarted()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetStarted()
	 * @method \Bitrix\Main\Type\DateTime fillStarted()
	 * @method \int getStartedBy()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setStartedBy(\int|\Bitrix\Main\DB\SqlExpression $startedBy)
	 * @method bool hasStartedBy()
	 * @method bool isStartedByFilled()
	 * @method bool isStartedByChanged()
	 * @method \int remindActualStartedBy()
	 * @method \int requireStartedBy()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetStartedBy()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetStartedBy()
	 * @method \int fillStartedBy()
	 * @method \string getIsPilotScheme()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setIsPilotScheme(\string|\Bitrix\Main\DB\SqlExpression $isPilotScheme)
	 * @method bool hasIsPilotScheme()
	 * @method bool isIsPilotSchemeFilled()
	 * @method bool isIsPilotSchemeChanged()
	 * @method \string remindActualIsPilotScheme()
	 * @method \string requireIsPilotScheme()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetIsPilotScheme()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetIsPilotScheme()
	 * @method \string fillIsPilotScheme()
	 * @method \string getPilotAudienceMatch()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setPilotAudienceMatch(\string|\Bitrix\Main\DB\SqlExpression $pilotAudienceMatch)
	 * @method bool hasPilotAudienceMatch()
	 * @method bool isPilotAudienceMatchFilled()
	 * @method bool isPilotAudienceMatchChanged()
	 * @method \string remindActualPilotAudienceMatch()
	 * @method \string requirePilotAudienceMatch()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetPilotAudienceMatch()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetPilotAudienceMatch()
	 * @method \string fillPilotAudienceMatch()
	 * @method \string getSchemeRevision()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setSchemeRevision(\string|\Bitrix\Main\DB\SqlExpression $schemeRevision)
	 * @method bool hasSchemeRevision()
	 * @method bool isSchemeRevisionFilled()
	 * @method bool isSchemeRevisionChanged()
	 * @method \string remindActualSchemeRevision()
	 * @method \string requireSchemeRevision()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetSchemeRevision()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetSchemeRevision()
	 * @method \string fillSchemeRevision()
	 * @method \Bitrix\Main\EO_User getStartedUser()
	 * @method \Bitrix\Main\EO_User remindActualStartedUser()
	 * @method \Bitrix\Main\EO_User requireStartedUser()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setStartedUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetStartedUser()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetStartedUser()
	 * @method bool hasStartedUser()
	 * @method bool isStartedUserFilled()
	 * @method bool isStartedUserChanged()
	 * @method \Bitrix\Main\EO_User fillStartedUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance getInstance()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance remindActualInstance()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance requireInstance()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setInstance(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance $object)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetInstance()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetInstance()
	 * @method bool hasInstance()
	 * @method bool isInstanceFilled()
	 * @method bool isInstanceChanged()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance fillInstance()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection getTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection requireTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection fillTasks()
	 * @method bool hasTasks()
	 * @method bool isTasksFilled()
	 * @method bool isTasksChanged()
	 * @method void addToTasks(\Bitrix\Bizproc\Workflow\Task $task)
	 * @method void removeFromTasks(\Bitrix\Bizproc\Workflow\Task $task)
	 * @method void removeAllTasks()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetTasks()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetTasks()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata getMeta()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata remindActualMeta()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata requireMeta()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setMeta(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata $object)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetMeta()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetMeta()
	 * @method bool hasMeta()
	 * @method bool isMetaFilled()
	 * @method bool isMetaChanged()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata fillMeta()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive getTasksArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive remindActualTasksArchive()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive requireTasksArchive()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState setTasksArchive(\Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive $object)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState resetTasksArchive()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unsetTasksArchive()
	 * @method bool hasTasksArchive()
	 * @method bool isTasksArchiveFilled()
	 * @method bool isTasksArchiveChanged()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive fillTasksArchive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\WorkflowState wakeUp($data)
	 */
	class EO_WorkflowState extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowState_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \int[] getDocumentIdIntList()
	 * @method \int[] fillDocumentIdInt()
	 * @method \int[] getWorkflowTemplateIdList()
	 * @method \int[] fillWorkflowTemplateId()
	 * @method \string[] getStateList()
	 * @method \string[] fillState()
	 * @method \string[] getStateTitleList()
	 * @method \string[] fillStateTitle()
	 * @method \string[] getStateParametersList()
	 * @method \string[] fillStateParameters()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \Bitrix\Main\Type\DateTime[] getStartedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStarted()
	 * @method \int[] getStartedByList()
	 * @method \int[] fillStartedBy()
	 * @method \string[] getIsPilotSchemeList()
	 * @method \string[] fillIsPilotScheme()
	 * @method \string[] getPilotAudienceMatchList()
	 * @method \string[] fillPilotAudienceMatch()
	 * @method \string[] getSchemeRevisionList()
	 * @method \string[] fillSchemeRevision()
	 * @method \Bitrix\Main\EO_User[] getStartedUserList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection getStartedUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillStartedUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance[] getInstanceList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection getInstanceCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection fillInstance()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection[] getTasksList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection getTasksCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection fillTasks()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata[] getMetaList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection getMetaCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection fillMeta()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive[] getTasksArchiveList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection getTasksArchiveCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection fillTasksArchive()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\WorkflowState $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\WorkflowState $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\WorkflowState $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection filter(callable $callback)
	 */
	class EO_WorkflowState_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowState_Query query()
	 * @method static EO_WorkflowState_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowState_Result getById($id)
	 * @method static EO_WorkflowState_Result getList(array $parameters = [])
	 * @method static EO_WorkflowState_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\WorkflowState createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\WorkflowState wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection wakeUpCollection($rows)
	 */
	class WorkflowStateTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowState_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection fetchCollection()
	 */
	class EO_WorkflowState_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection fetchCollection()
	 */
	class EO_WorkflowState_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowState_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable */
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * Tpl
	 * @see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \string getDocumentStatus()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setDocumentStatus(\string|\Bitrix\Main\DB\SqlExpression $documentStatus)
	 * @method bool hasDocumentStatus()
	 * @method bool isDocumentStatusFilled()
	 * @method bool isDocumentStatusChanged()
	 * @method \string remindActualDocumentStatus()
	 * @method \string requireDocumentStatus()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetDocumentStatus()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetDocumentStatus()
	 * @method \string fillDocumentStatus()
	 * @method \int getAutoExecute()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setAutoExecute(\int|\Bitrix\Main\DB\SqlExpression $autoExecute)
	 * @method bool hasAutoExecute()
	 * @method bool isAutoExecuteFilled()
	 * @method bool isAutoExecuteChanged()
	 * @method \int remindActualAutoExecute()
	 * @method \int requireAutoExecute()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetAutoExecute()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetAutoExecute()
	 * @method \int fillAutoExecute()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetDescription()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetDescription()
	 * @method \string fillDescription()
	 * @method array getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setTemplate(array|\Bitrix\Main\DB\SqlExpression $template)
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method array remindActualTemplate()
	 * @method array requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetTemplate()
	 * @method array fillTemplate()
	 * @method array getParameters()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setParameters(array|\Bitrix\Main\DB\SqlExpression $parameters)
	 * @method bool hasParameters()
	 * @method bool isParametersFilled()
	 * @method bool isParametersChanged()
	 * @method array remindActualParameters()
	 * @method array requireParameters()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetParameters()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetParameters()
	 * @method array fillParameters()
	 * @method array getVariables()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setVariables(array|\Bitrix\Main\DB\SqlExpression $variables)
	 * @method bool hasVariables()
	 * @method bool isVariablesFilled()
	 * @method bool isVariablesChanged()
	 * @method array remindActualVariables()
	 * @method array requireVariables()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetVariables()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetVariables()
	 * @method array fillVariables()
	 * @method array getConstants()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setConstants(array|\Bitrix\Main\DB\SqlExpression $constants)
	 * @method bool hasConstants()
	 * @method bool isConstantsFilled()
	 * @method bool isConstantsChanged()
	 * @method array remindActualConstants()
	 * @method array requireConstants()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetConstants()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetConstants()
	 * @method array fillConstants()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method \boolean getIsModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setIsModified(\boolean|\Bitrix\Main\DB\SqlExpression $isModified)
	 * @method bool hasIsModified()
	 * @method bool isIsModifiedFilled()
	 * @method bool isIsModifiedChanged()
	 * @method \boolean remindActualIsModified()
	 * @method \boolean requireIsModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetIsModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetIsModified()
	 * @method \boolean fillIsModified()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getSystemCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setSystemCode(\string|\Bitrix\Main\DB\SqlExpression $systemCode)
	 * @method bool hasSystemCode()
	 * @method bool isSystemCodeFilled()
	 * @method bool isSystemCodeChanged()
	 * @method \string remindActualSystemCode()
	 * @method \string requireSystemCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetSystemCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetSystemCode()
	 * @method \string fillSystemCode()
	 * @method \boolean getActive()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setActive(\boolean|\Bitrix\Main\DB\SqlExpression $active)
	 * @method bool hasActive()
	 * @method bool isActiveFilled()
	 * @method bool isActiveChanged()
	 * @method \boolean remindActualActive()
	 * @method \boolean requireActive()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetActive()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetActive()
	 * @method \boolean fillActive()
	 * @method \string getOriginatorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setOriginatorId(\string|\Bitrix\Main\DB\SqlExpression $originatorId)
	 * @method bool hasOriginatorId()
	 * @method bool isOriginatorIdFilled()
	 * @method bool isOriginatorIdChanged()
	 * @method \string remindActualOriginatorId()
	 * @method \string requireOriginatorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetOriginatorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetOriginatorId()
	 * @method \string fillOriginatorId()
	 * @method \string getOriginId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setOriginId(\string|\Bitrix\Main\DB\SqlExpression $originId)
	 * @method bool hasOriginId()
	 * @method bool isOriginIdFilled()
	 * @method bool isOriginIdChanged()
	 * @method \string remindActualOriginId()
	 * @method \string requireOriginId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetOriginId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetOriginId()
	 * @method \string fillOriginId()
	 * @method \Bitrix\Main\EO_User getUser()
	 * @method \Bitrix\Main\EO_User remindActualUser()
	 * @method \Bitrix\Main\EO_User requireUser()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetUser()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetUser()
	 * @method bool hasUser()
	 * @method bool isUserFilled()
	 * @method bool isUserChanged()
	 * @method \Bitrix\Main\EO_User fillUser()
	 * @method \boolean getIsSystem()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setIsSystem(\boolean|\Bitrix\Main\DB\SqlExpression $isSystem)
	 * @method bool hasIsSystem()
	 * @method bool isIsSystemFilled()
	 * @method bool isIsSystemChanged()
	 * @method \boolean remindActualIsSystem()
	 * @method \boolean requireIsSystem()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetIsSystem()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetIsSystem()
	 * @method \boolean fillIsSystem()
	 * @method \int getSort()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetSort()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetSort()
	 * @method \int fillSort()
	 * @method \string getType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetType()
	 * @method \string fillType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection getTemplateSettings()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection requireTemplateSettings()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection fillTemplateSettings()
	 * @method bool hasTemplateSettings()
	 * @method bool isTemplateSettingsFilled()
	 * @method bool isTemplateSettingsChanged()
	 * @method void addToTemplateSettings(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings $workflowTemplateSettings)
	 * @method void removeFromTemplateSettings(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings $workflowTemplateSettings)
	 * @method void removeAllTemplateSettings()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetTemplateSettings()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetTemplateSettings()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection getTemplateDraft()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection requireTemplateDraft()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection fillTemplateDraft()
	 * @method bool hasTemplateDraft()
	 * @method bool isTemplateDraftFilled()
	 * @method bool isTemplateDraftChanged()
	 * @method void addToTemplateDraft(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft $workflowTemplateDraft)
	 * @method void removeFromTemplateDraft(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft $workflowTemplateDraft)
	 * @method void removeAllTemplateDraft()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetTemplateDraft()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetTemplateDraft()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \int getUpdatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setUpdatedBy(\int|\Bitrix\Main\DB\SqlExpression $updatedBy)
	 * @method bool hasUpdatedBy()
	 * @method bool isUpdatedByFilled()
	 * @method bool isUpdatedByChanged()
	 * @method \int remindActualUpdatedBy()
	 * @method \int requireUpdatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetUpdatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetUpdatedBy()
	 * @method \int fillUpdatedBy()
	 * @method \int getActivatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setActivatedBy(\int|\Bitrix\Main\DB\SqlExpression $activatedBy)
	 * @method bool hasActivatedBy()
	 * @method bool isActivatedByFilled()
	 * @method bool isActivatedByChanged()
	 * @method \int remindActualActivatedBy()
	 * @method \int requireActivatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetActivatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetActivatedBy()
	 * @method \int fillActivatedBy()
	 * @method \Bitrix\Main\Type\DateTime getActivatedAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setActivatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $activatedAt)
	 * @method bool hasActivatedAt()
	 * @method bool isActivatedAtFilled()
	 * @method bool isActivatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualActivatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireActivatedAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetActivatedAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetActivatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillActivatedAt()
	 * @method \string getCreateSource()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setCreateSource(\string|\Bitrix\Main\DB\SqlExpression $createSource)
	 * @method bool hasCreateSource()
	 * @method bool isCreateSourceFilled()
	 * @method bool isCreateSourceChanged()
	 * @method \string remindActualCreateSource()
	 * @method \string requireCreateSource()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetCreateSource()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetCreateSource()
	 * @method \string fillCreateSource()
	 * @method null|\Bitrix\Main\Type\DateTime getFirstPublishedAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setFirstPublishedAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $firstPublishedAt)
	 * @method bool hasFirstPublishedAt()
	 * @method bool isFirstPublishedAtFilled()
	 * @method bool isFirstPublishedAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualFirstPublishedAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireFirstPublishedAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetFirstPublishedAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetFirstPublishedAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillFirstPublishedAt()
	 * @method \Bitrix\Main\EO_User getUpdatedUser()
	 * @method \Bitrix\Main\EO_User remindActualUpdatedUser()
	 * @method \Bitrix\Main\EO_User requireUpdatedUser()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setUpdatedUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetUpdatedUser()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetUpdatedUser()
	 * @method bool hasUpdatedUser()
	 * @method bool isUpdatedUserFilled()
	 * @method bool isUpdatedUserChanged()
	 * @method \Bitrix\Main\EO_User fillUpdatedUser()
	 * @method \Bitrix\Main\EO_User getCreatedUser()
	 * @method \Bitrix\Main\EO_User remindActualCreatedUser()
	 * @method \Bitrix\Main\EO_User requireCreatedUser()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl setCreatedUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl resetCreatedUser()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unsetCreatedUser()
	 * @method bool hasCreatedUser()
	 * @method bool isCreatedUserFilled()
	 * @method bool isCreatedUserChanged()
	 * @method \Bitrix\Main\EO_User fillCreatedUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Tpl wakeUp($data)
	 */
	class EO_WorkflowTemplate extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * EO_WorkflowTemplate_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \string[] getDocumentStatusList()
	 * @method \string[] fillDocumentStatus()
	 * @method \int[] getAutoExecuteList()
	 * @method \int[] fillAutoExecute()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method array[] getTemplateList()
	 * @method array[] fillTemplate()
	 * @method array[] getParametersList()
	 * @method array[] fillParameters()
	 * @method array[] getVariablesList()
	 * @method array[] fillVariables()
	 * @method array[] getConstantsList()
	 * @method array[] fillConstants()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \boolean[] getIsModifiedList()
	 * @method \boolean[] fillIsModified()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getSystemCodeList()
	 * @method \string[] fillSystemCode()
	 * @method \boolean[] getActiveList()
	 * @method \boolean[] fillActive()
	 * @method \string[] getOriginatorIdList()
	 * @method \string[] fillOriginatorId()
	 * @method \string[] getOriginIdList()
	 * @method \string[] fillOriginId()
	 * @method \Bitrix\Main\EO_User[] getUserList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection getUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUser()
	 * @method \boolean[] getIsSystemList()
	 * @method \boolean[] fillIsSystem()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection[] getTemplateSettingsList()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection getTemplateSettingsCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection fillTemplateSettings()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection[] getTemplateDraftList()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection getTemplateDraftCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection fillTemplateDraft()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \int[] getUpdatedByList()
	 * @method \int[] fillUpdatedBy()
	 * @method \int[] getActivatedByList()
	 * @method \int[] fillActivatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getActivatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillActivatedAt()
	 * @method \string[] getCreateSourceList()
	 * @method \string[] fillCreateSource()
	 * @method null|\Bitrix\Main\Type\DateTime[] getFirstPublishedAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillFirstPublishedAt()
	 * @method \Bitrix\Main\EO_User[] getUpdatedUserList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection getUpdatedUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUpdatedUser()
	 * @method \Bitrix\Main\EO_User[] getCreatedUserList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection getCreatedUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillCreatedUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplate_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * @method static EO_WorkflowTemplate_Query query()
	 * @method static EO_WorkflowTemplate_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplate_Result getById($id)
	 * @method static EO_WorkflowTemplate_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplate_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Tpl createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Tpl wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplate_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fetchCollection()
	 */
	class EO_WorkflowTemplate_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fetchCollection()
	 */
	class EO_WorkflowTemplate_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplate_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable */
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateChange
	 * @see \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getEventType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setEventType(\int|\Bitrix\Main\DB\SqlExpression $eventType)
	 * @method bool hasEventType()
	 * @method bool isEventTypeFilled()
	 * @method bool isEventTypeChanged()
	 * @method \int remindActualEventType()
	 * @method \int requireEventType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetEventType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetEventType()
	 * @method \int fillEventType()
	 * @method null|\int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setUserId(null|\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method null|\int remindActualUserId()
	 * @method null|\int requireUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetUserId()
	 * @method null|\int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 * @method null|\int getVersionNumber()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setVersionNumber(null|\int|\Bitrix\Main\DB\SqlExpression $versionNumber)
	 * @method bool hasVersionNumber()
	 * @method bool isVersionNumberFilled()
	 * @method bool isVersionNumberChanged()
	 * @method null|\int remindActualVersionNumber()
	 * @method null|\int requireVersionNumber()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetVersionNumber()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetVersionNumber()
	 * @method null|\int fillVersionNumber()
	 * @method null|\int getPublicationType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setPublicationType(null|\int|\Bitrix\Main\DB\SqlExpression $publicationType)
	 * @method bool hasPublicationType()
	 * @method bool isPublicationTypeFilled()
	 * @method bool isPublicationTypeChanged()
	 * @method null|\int remindActualPublicationType()
	 * @method null|\int requirePublicationType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetPublicationType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetPublicationType()
	 * @method null|\int fillPublicationType()
	 * @method null|array getSnapshot()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setSnapshot(null|array|\Bitrix\Main\DB\SqlExpression $snapshot)
	 * @method bool hasSnapshot()
	 * @method bool isSnapshotFilled()
	 * @method bool isSnapshotChanged()
	 * @method null|array remindActualSnapshot()
	 * @method null|array requireSnapshot()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetSnapshot()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetSnapshot()
	 * @method null|array fillSnapshot()
	 * @method null|\Bitrix\Main\Type\DateTime getTemplateModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setTemplateModified(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $templateModified)
	 * @method bool hasTemplateModified()
	 * @method bool isTemplateModifiedFilled()
	 * @method bool isTemplateModifiedChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualTemplateModified()
	 * @method null|\Bitrix\Main\Type\DateTime requireTemplateModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetTemplateModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetTemplateModified()
	 * @method null|\Bitrix\Main\Type\DateTime fillTemplateModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange wakeUp($data)
	 */
	class EO_WorkflowTemplateChange extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateChange_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getEventTypeList()
	 * @method \int[] fillEventType()
	 * @method null|\int[] getUserIdList()
	 * @method null|\int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 * @method null|\int[] getVersionNumberList()
	 * @method null|\int[] fillVersionNumber()
	 * @method null|\int[] getPublicationTypeList()
	 * @method null|\int[] fillPublicationType()
	 * @method null|array[] getSnapshotList()
	 * @method null|array[] fillSnapshot()
	 * @method null|\Bitrix\Main\Type\DateTime[] getTemplateModifiedList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillTemplateModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection merge(?\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateChange_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * @method static EO_WorkflowTemplateChange_Query query()
	 * @method static EO_WorkflowTemplateChange_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateChange_Result getById($id)
	 * @method static EO_WorkflowTemplateChange_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateChange_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateChangeTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateChange_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateChange_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateChange_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateChange_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable */
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateUserOption
	 * @see \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption resetUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getOptionCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption setOptionCode(\int|\Bitrix\Main\DB\SqlExpression $optionCode)
	 * @method bool hasOptionCode()
	 * @method bool isOptionCodeFilled()
	 * @method bool isOptionCodeChanged()
	 * @method \int remindActualOptionCode()
	 * @method \int requireOptionCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption resetOptionCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption unsetOptionCode()
	 * @method \int fillOptionCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption wakeUp($data)
	 */
	class EO_WorkflowTemplateUserOption extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateUserOption_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getOptionCodeList()
	 * @method \int[] fillOptionCode()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection merge(?\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateUserOption_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * @method static EO_WorkflowTemplateUserOption_Query query()
	 * @method static EO_WorkflowTemplateUserOption_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateUserOption_Result getById($id)
	 * @method static EO_WorkflowTemplateUserOption_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateUserOption_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateUserOptionTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateUserOption_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateUserOption_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateUserOption_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateUserOption_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateUserOption_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable */
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * EO_WorkflowTemplateTrigger
	 * @see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \string getTriggerName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setTriggerName(\string|\Bitrix\Main\DB\SqlExpression $triggerName)
	 * @method bool hasTriggerName()
	 * @method bool isTriggerNameFilled()
	 * @method bool isTriggerNameChanged()
	 * @method \string getTriggerType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setTriggerType(\string|\Bitrix\Main\DB\SqlExpression $triggerType)
	 * @method bool hasTriggerType()
	 * @method bool isTriggerTypeFilled()
	 * @method bool isTriggerTypeChanged()
	 * @method \string remindActualTriggerType()
	 * @method \string requireTriggerType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger resetTriggerType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unsetTriggerType()
	 * @method \string fillTriggerType()
	 * @method array getApplyRules()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setApplyRules(array|\Bitrix\Main\DB\SqlExpression $applyRules)
	 * @method bool hasApplyRules()
	 * @method bool isApplyRulesFilled()
	 * @method bool isApplyRulesChanged()
	 * @method array remindActualApplyRules()
	 * @method array requireApplyRules()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger resetApplyRules()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unsetApplyRules()
	 * @method array fillApplyRules()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger resetModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger resetEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger resetDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger wakeUp($data)
	 */
	class EO_WorkflowTemplateTrigger extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * EO_WorkflowTemplateTrigger_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getTemplateIdList()
	 * @method \string[] getTriggerNameList()
	 * @method \string[] getTriggerTypeList()
	 * @method \string[] fillTriggerType()
	 * @method array[] getApplyRulesList()
	 * @method array[] fillApplyRules()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateTrigger_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * @method static EO_WorkflowTemplateTrigger_Query query()
	 * @method static EO_WorkflowTemplateTrigger_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateTrigger_Result getById($id)
	 * @method static EO_WorkflowTemplateTrigger_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateTrigger_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateTriggerTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateTrigger_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateTrigger_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateTrigger_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateTrigger_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateTrigger_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable */
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * EO_WorkflowTemplateUserData
	 * @see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \string getEntityId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData setEntityId(\string|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \string remindActualEntityId()
	 * @method \string requireEntityId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData resetEntityId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData unsetEntityId()
	 * @method \string fillEntityId()
	 * @method \string getType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData resetType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData unsetType()
	 * @method \string fillType()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData resetName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData unsetName()
	 * @method \string fillName()
	 * @method \string getValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData setValue(\string|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \string remindActualValue()
	 * @method \string requireValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData resetValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData unsetValue()
	 * @method \string fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData wakeUp($data)
	 */
	class EO_WorkflowTemplateUserData extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * EO_WorkflowTemplateUserData_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \string[] getEntityIdList()
	 * @method \string[] fillEntityId()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getValueList()
	 * @method \string[] fillValue()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateUserData_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * @method static EO_WorkflowTemplateUserData_Query query()
	 * @method static EO_WorkflowTemplateUserData_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateUserData_Result getById($id)
	 * @method static EO_WorkflowTemplateUserData_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateUserData_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateUserDataTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateUserData_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateUserData_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateUserData_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateUserData_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateUserData_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable */
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * TemplateSection
	 * @see \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \string getSectionId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection setSectionId(\string|\Bitrix\Main\DB\SqlExpression $sectionId)
	 * @method bool hasSectionId()
	 * @method bool isSectionIdFilled()
	 * @method bool isSectionIdChanged()
	 * @method \string remindActualSectionId()
	 * @method \string requireSectionId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection resetSectionId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection unsetSectionId()
	 * @method \string fillSectionId()
	 * @method \string getPath()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection setPath(\string|\Bitrix\Main\DB\SqlExpression $path)
	 * @method bool hasPath()
	 * @method bool isPathFilled()
	 * @method bool isPathChanged()
	 * @method \string remindActualPath()
	 * @method \string requirePath()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection resetPath()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection unsetPath()
	 * @method \string fillPath()
	 * @method \Bitrix\Main\Type\DateTime getDateModify()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection setDateModify(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateModify)
	 * @method bool hasDateModify()
	 * @method bool isDateModifyFilled()
	 * @method bool isDateModifyChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateModify()
	 * @method \Bitrix\Main\Type\DateTime requireDateModify()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection resetDateModify()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection unsetDateModify()
	 * @method \Bitrix\Main\Type\DateTime fillDateModify()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection wakeUp($data)
	 */
	class EO_WorkflowTemplateSection extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * EO_WorkflowTemplateSection_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \string[] getSectionIdList()
	 * @method \string[] fillSectionId()
	 * @method \string[] getPathList()
	 * @method \string[] fillPath()
	 * @method \Bitrix\Main\Type\DateTime[] getDateModifyList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateModify()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateSection_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Entity {
	/**
	 * @method static EO_WorkflowTemplateSection_Query query()
	 * @method static EO_WorkflowTemplateSection_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateSection_Result getById($id)
	 * @method static EO_WorkflowTemplateSection_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateSection_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateSectionTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateSection_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateSection_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateSection_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\TemplateSection wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplateSection_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateSection_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable */
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateSettings
	 * @see \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings resetName()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings unsetName()
	 * @method \string fillName()
	 * @method \string getValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings setValue(\string|\Bitrix\Main\DB\SqlExpression $value)
	 * @method bool hasValue()
	 * @method bool isValueFilled()
	 * @method bool isValueChanged()
	 * @method \string remindActualValue()
	 * @method \string requireValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings resetValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings unsetValue()
	 * @method \string fillValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings wakeUp($data)
	 */
	class EO_WorkflowTemplateSettings extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateSettings_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getValueList()
	 * @method \string[] fillValue()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection merge(?\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateSettings_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * @method static EO_WorkflowTemplateSettings_Query query()
	 * @method static EO_WorkflowTemplateSettings_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateSettings_Result getById($id)
	 * @method static EO_WorkflowTemplateSettings_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateSettings_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateSettingsTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateSettings_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateSettings_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateSettings_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateSettings_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateSettings_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable */
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateDraft
	 * @see \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetEntity()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setDocumentType(\string|\Bitrix\Main\DB\SqlExpression $documentType)
	 * @method bool hasDocumentType()
	 * @method bool isDocumentTypeFilled()
	 * @method bool isDocumentTypeChanged()
	 * @method \string remindActualDocumentType()
	 * @method \string requireDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetDocumentType()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetDocumentType()
	 * @method \string fillDocumentType()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method array getTemplateData()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setTemplateData(array|\Bitrix\Main\DB\SqlExpression $templateData)
	 * @method bool hasTemplateData()
	 * @method bool isTemplateDataFilled()
	 * @method bool isTemplateDataChanged()
	 * @method array remindActualTemplateData()
	 * @method array requireTemplateData()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetTemplateData()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetTemplateData()
	 * @method array fillTemplateData()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetStatus()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetStatus()
	 * @method \int fillStatus()
	 * @method \int getRevision()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setRevision(\int|\Bitrix\Main\DB\SqlExpression $revision)
	 * @method bool hasRevision()
	 * @method bool isRevisionFilled()
	 * @method bool isRevisionChanged()
	 * @method \int remindActualRevision()
	 * @method \int requireRevision()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetRevision()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetRevision()
	 * @method \int fillRevision()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft wakeUp($data)
	 */
	class EO_WorkflowTemplateDraft extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateDraft_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentTypeList()
	 * @method \string[] fillDocumentType()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method array[] getTemplateDataList()
	 * @method array[] fillTemplateData()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection merge(?\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateDraft_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * @method static EO_WorkflowTemplateDraft_Query query()
	 * @method static EO_WorkflowTemplateDraft_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowTemplateDraft_Result getById($id)
	 * @method static EO_WorkflowTemplateDraft_Result getList(array $parameters = [])
	 * @method static EO_WorkflowTemplateDraft_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection wakeUpCollection($rows)
	 */
	class WorkflowTemplateDraftTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateDraft_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateDraft_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateDraft_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateDraft_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Task\TaskUserTable */
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * EO_TaskUser
	 * @see \Bitrix\Bizproc\Workflow\Task\TaskUserTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getTaskId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setTaskId(\int|\Bitrix\Main\DB\SqlExpression $taskId)
	 * @method bool hasTaskId()
	 * @method bool isTaskIdFilled()
	 * @method bool isTaskIdChanged()
	 * @method \int remindActualTaskId()
	 * @method \int requireTaskId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetTaskId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetTaskId()
	 * @method \int fillTaskId()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetStatus()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetStatus()
	 * @method \int fillStatus()
	 * @method null|\Bitrix\Main\Type\DateTime getDateUpdate()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setDateUpdate(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateUpdate)
	 * @method bool hasDateUpdate()
	 * @method bool isDateUpdateFilled()
	 * @method bool isDateUpdateChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualDateUpdate()
	 * @method null|\Bitrix\Main\Type\DateTime requireDateUpdate()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetDateUpdate()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetDateUpdate()
	 * @method null|\Bitrix\Main\Type\DateTime fillDateUpdate()
	 * @method \int getOriginalUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setOriginalUserId(\int|\Bitrix\Main\DB\SqlExpression $originalUserId)
	 * @method bool hasOriginalUserId()
	 * @method bool isOriginalUserIdFilled()
	 * @method bool isOriginalUserIdChanged()
	 * @method \int remindActualOriginalUserId()
	 * @method \int requireOriginalUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetOriginalUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetOriginalUserId()
	 * @method \int fillOriginalUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task getUserTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task remindActualUserTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task requireUserTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setUserTasks(\Bitrix\Bizproc\Workflow\Task $object)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetUserTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetUserTasks()
	 * @method bool hasUserTasks()
	 * @method bool isUserTasksFilled()
	 * @method bool isUserTasksChanged()
	 * @method \Bitrix\Bizproc\Workflow\Task fillUserTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent getUserTasksSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent remindActualUserTasksSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent requireUserTasksSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser setUserTasksSearchContent(\Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent $object)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser resetUserTasksSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unsetUserTasksSearchContent()
	 * @method bool hasUserTasksSearchContent()
	 * @method bool isUserTasksSearchContentFilled()
	 * @method bool isUserTasksSearchContentChanged()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent fillUserTasksSearchContent()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskUser wakeUp($data)
	 */
	class EO_TaskUser extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Task\TaskUserTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Task\TaskUserTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * EO_TaskUser_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getTaskIdList()
	 * @method \int[] fillTaskId()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method null|\Bitrix\Main\Type\DateTime[] getDateUpdateList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillDateUpdate()
	 * @method \int[] getOriginalUserIdList()
	 * @method \int[] fillOriginalUserId()
	 * @method \Bitrix\Bizproc\Workflow\Task[] getUserTasksList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection getUserTasksCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection fillUserTasks()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent[] getUserTasksSearchContentList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection getUserTasksSearchContentCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection fillUserTasksSearchContent()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection merge(?\Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection filter(callable $callback)
	 */
	class EO_TaskUser_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Task\TaskUserTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Task\TaskUserTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * @method static EO_TaskUser_Query query()
	 * @method static EO_TaskUser_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TaskUser_Result getById($id)
	 * @method static EO_TaskUser_Result getList(array $parameters = [])
	 * @method static EO_TaskUser_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskUser createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskUser wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection wakeUpCollection($rows)
	 */
	class TaskUserTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TaskUser_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection fetchCollection()
	 */
	class EO_TaskUser_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection fetchCollection()
	 */
	class EO_TaskUser_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection wakeUpCollection($rows)
	 */
	class EO_TaskUser_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Task\TaskSearchContentTable */
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * EO_TaskSearchContent
	 * @see \Bitrix\Bizproc\Workflow\Task\TaskSearchContentTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getTaskId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent setTaskId(\int|\Bitrix\Main\DB\SqlExpression $taskId)
	 * @method bool hasTaskId()
	 * @method bool isTaskIdFilled()
	 * @method bool isTaskIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent resetWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \string getSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent setSearchContent(\string|\Bitrix\Main\DB\SqlExpression $searchContent)
	 * @method bool hasSearchContent()
	 * @method bool isSearchContentFilled()
	 * @method bool isSearchContentChanged()
	 * @method \string remindActualSearchContent()
	 * @method \string requireSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent resetSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent unsetSearchContent()
	 * @method \string fillSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task getTask()
	 * @method \Bitrix\Bizproc\Workflow\Task remindActualTask()
	 * @method \Bitrix\Bizproc\Workflow\Task requireTask()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent setTask(\Bitrix\Bizproc\Workflow\Task $object)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent resetTask()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent unsetTask()
	 * @method bool hasTask()
	 * @method bool isTaskFilled()
	 * @method bool isTaskChanged()
	 * @method \Bitrix\Bizproc\Workflow\Task fillTask()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection getUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection requireUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection fillUsers()
	 * @method bool hasUsers()
	 * @method bool isUsersFilled()
	 * @method bool isUsersChanged()
	 * @method void addToUsers(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $taskUser)
	 * @method void removeFromUsers(\Bitrix\Bizproc\Workflow\Task\EO_TaskUser $taskUser)
	 * @method void removeAllUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent resetUsers()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent unsetUsers()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent wakeUp($data)
	 */
	class EO_TaskSearchContent extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Task\TaskSearchContentTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Task\TaskSearchContentTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * EO_TaskSearchContent_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getTaskIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \string[] getSearchContentList()
	 * @method \string[] fillSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task[] getTaskList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection getTaskCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_Task_Collection fillTask()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection[] getUsersList()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection getUsersCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskUser_Collection fillUsers()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection merge(?\Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection filter(callable $callback)
	 */
	class EO_TaskSearchContent_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Task\TaskSearchContentTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Task\TaskSearchContentTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Task {
	/**
	 * @method static EO_TaskSearchContent_Query query()
	 * @method static EO_TaskSearchContent_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TaskSearchContent_Result getById($id)
	 * @method static EO_TaskSearchContent_Result getList(array $parameters = [])
	 * @method static EO_TaskSearchContent_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection wakeUpCollection($rows)
	 */
	class TaskSearchContentTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TaskSearchContent_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection fetchCollection()
	 */
	class EO_TaskSearchContent_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection fetchCollection()
	 */
	class EO_TaskSearchContent_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection wakeUpCollection($rows)
	 */
	class EO_TaskSearchContent_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Type\Entity\GlobalConstTable */
namespace Bitrix\Bizproc\Workflow\Type\Entity {
	/**
	 * EO_GlobalConst
	 * @see \Bitrix\Bizproc\Workflow\Type\Entity\GlobalConstTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getId()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setId(\string|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetName()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetDescription()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetDescription()
	 * @method \string fillDescription()
	 * @method \string getPropertyType()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setPropertyType(\string|\Bitrix\Main\DB\SqlExpression $propertyType)
	 * @method bool hasPropertyType()
	 * @method bool isPropertyTypeFilled()
	 * @method bool isPropertyTypeChanged()
	 * @method \string remindActualPropertyType()
	 * @method \string requirePropertyType()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetPropertyType()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetPropertyType()
	 * @method \string fillPropertyType()
	 * @method \boolean getIsRequired()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setIsRequired(\boolean|\Bitrix\Main\DB\SqlExpression $isRequired)
	 * @method bool hasIsRequired()
	 * @method bool isIsRequiredFilled()
	 * @method bool isIsRequiredChanged()
	 * @method \boolean remindActualIsRequired()
	 * @method \boolean requireIsRequired()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetIsRequired()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetIsRequired()
	 * @method \boolean fillIsRequired()
	 * @method \boolean getIsMultiple()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setIsMultiple(\boolean|\Bitrix\Main\DB\SqlExpression $isMultiple)
	 * @method bool hasIsMultiple()
	 * @method bool isIsMultipleFilled()
	 * @method bool isIsMultipleChanged()
	 * @method \boolean remindActualIsMultiple()
	 * @method \boolean requireIsMultiple()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetIsMultiple()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetIsMultiple()
	 * @method \boolean fillIsMultiple()
	 * @method \string getPropertyOptions()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setPropertyOptions(\string|\Bitrix\Main\DB\SqlExpression $propertyOptions)
	 * @method bool hasPropertyOptions()
	 * @method bool isPropertyOptionsFilled()
	 * @method bool isPropertyOptionsChanged()
	 * @method \string remindActualPropertyOptions()
	 * @method \string requirePropertyOptions()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetPropertyOptions()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetPropertyOptions()
	 * @method \string fillPropertyOptions()
	 * @method \string getPropertySettings()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setPropertySettings(\string|\Bitrix\Main\DB\SqlExpression $propertySettings)
	 * @method bool hasPropertySettings()
	 * @method bool isPropertySettingsFilled()
	 * @method bool isPropertySettingsChanged()
	 * @method \string remindActualPropertySettings()
	 * @method \string requirePropertySettings()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetPropertySettings()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetPropertySettings()
	 * @method \string fillPropertySettings()
	 * @method \string getPropertyValue()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setPropertyValue(\string|\Bitrix\Main\DB\SqlExpression $propertyValue)
	 * @method bool hasPropertyValue()
	 * @method bool isPropertyValueFilled()
	 * @method bool isPropertyValueChanged()
	 * @method \string remindActualPropertyValue()
	 * @method \string requirePropertyValue()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetPropertyValue()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetPropertyValue()
	 * @method \string fillPropertyValue()
	 * @method \Bitrix\Main\Type\DateTime getCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setCreatedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdDate)
	 * @method bool hasCreatedDate()
	 * @method bool isCreatedDateFilled()
	 * @method bool isCreatedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedDate()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \string getVisibility()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setVisibility(\string|\Bitrix\Main\DB\SqlExpression $visibility)
	 * @method bool hasVisibility()
	 * @method bool isVisibilityFilled()
	 * @method bool isVisibilityChanged()
	 * @method \string remindActualVisibility()
	 * @method \string requireVisibility()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetVisibility()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetVisibility()
	 * @method \string fillVisibility()
	 * @method \Bitrix\Main\Type\DateTime getModifiedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setModifiedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modifiedDate)
	 * @method bool hasModifiedDate()
	 * @method bool isModifiedDateFilled()
	 * @method bool isModifiedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime requireModifiedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetModifiedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime fillModifiedDate()
	 * @method \int getModifiedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst setModifiedBy(\int|\Bitrix\Main\DB\SqlExpression $modifiedBy)
	 * @method bool hasModifiedBy()
	 * @method bool isModifiedByFilled()
	 * @method bool isModifiedByChanged()
	 * @method \int remindActualModifiedBy()
	 * @method \int requireModifiedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst resetModifiedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unsetModifiedBy()
	 * @method \int fillModifiedBy()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst wakeUp($data)
	 */
	class EO_GlobalConst extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Type\Entity\GlobalConstTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Type\Entity\GlobalConstTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Type\Entity {
	/**
	 * EO_GlobalConst_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIdList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \string[] getPropertyTypeList()
	 * @method \string[] fillPropertyType()
	 * @method \boolean[] getIsRequiredList()
	 * @method \boolean[] fillIsRequired()
	 * @method \boolean[] getIsMultipleList()
	 * @method \boolean[] fillIsMultiple()
	 * @method \string[] getPropertyOptionsList()
	 * @method \string[] fillPropertyOptions()
	 * @method \string[] getPropertySettingsList()
	 * @method \string[] fillPropertySettings()
	 * @method \string[] getPropertyValueList()
	 * @method \string[] fillPropertyValue()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedDate()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \string[] getVisibilityList()
	 * @method \string[] fillVisibility()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModifiedDate()
	 * @method \int[] getModifiedByList()
	 * @method \int[] fillModifiedBy()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection merge(?\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection filter(callable $callback)
	 */
	class EO_GlobalConst_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Type\Entity\GlobalConstTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Type\Entity\GlobalConstTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Type\Entity {
	/**
	 * @method static EO_GlobalConst_Query query()
	 * @method static EO_GlobalConst_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_GlobalConst_Result getById($id)
	 * @method static EO_GlobalConst_Result getList(array $parameters = [])
	 * @method static EO_GlobalConst_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection wakeUpCollection($rows)
	 */
	class GlobalConstTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_GlobalConst_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection fetchCollection()
	 */
	class EO_GlobalConst_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection fetchCollection()
	 */
	class EO_GlobalConst_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalConst_Collection wakeUpCollection($rows)
	 */
	class EO_GlobalConst_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Type\Entity\GlobalVarTable */
namespace Bitrix\Bizproc\Workflow\Type\Entity {
	/**
	 * EO_GlobalVar
	 * @see \Bitrix\Bizproc\Workflow\Type\Entity\GlobalVarTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getId()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setId(\string|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetName()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetName()
	 * @method \string fillName()
	 * @method \string getDescription()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetDescription()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetDescription()
	 * @method \string fillDescription()
	 * @method \string getPropertyType()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setPropertyType(\string|\Bitrix\Main\DB\SqlExpression $propertyType)
	 * @method bool hasPropertyType()
	 * @method bool isPropertyTypeFilled()
	 * @method bool isPropertyTypeChanged()
	 * @method \string remindActualPropertyType()
	 * @method \string requirePropertyType()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetPropertyType()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetPropertyType()
	 * @method \string fillPropertyType()
	 * @method \boolean getIsRequired()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setIsRequired(\boolean|\Bitrix\Main\DB\SqlExpression $isRequired)
	 * @method bool hasIsRequired()
	 * @method bool isIsRequiredFilled()
	 * @method bool isIsRequiredChanged()
	 * @method \boolean remindActualIsRequired()
	 * @method \boolean requireIsRequired()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetIsRequired()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetIsRequired()
	 * @method \boolean fillIsRequired()
	 * @method \boolean getIsMultiple()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setIsMultiple(\boolean|\Bitrix\Main\DB\SqlExpression $isMultiple)
	 * @method bool hasIsMultiple()
	 * @method bool isIsMultipleFilled()
	 * @method bool isIsMultipleChanged()
	 * @method \boolean remindActualIsMultiple()
	 * @method \boolean requireIsMultiple()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetIsMultiple()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetIsMultiple()
	 * @method \boolean fillIsMultiple()
	 * @method \string getPropertyOptions()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setPropertyOptions(\string|\Bitrix\Main\DB\SqlExpression $propertyOptions)
	 * @method bool hasPropertyOptions()
	 * @method bool isPropertyOptionsFilled()
	 * @method bool isPropertyOptionsChanged()
	 * @method \string remindActualPropertyOptions()
	 * @method \string requirePropertyOptions()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetPropertyOptions()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetPropertyOptions()
	 * @method \string fillPropertyOptions()
	 * @method \string getPropertySettings()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setPropertySettings(\string|\Bitrix\Main\DB\SqlExpression $propertySettings)
	 * @method bool hasPropertySettings()
	 * @method bool isPropertySettingsFilled()
	 * @method bool isPropertySettingsChanged()
	 * @method \string remindActualPropertySettings()
	 * @method \string requirePropertySettings()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetPropertySettings()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetPropertySettings()
	 * @method \string fillPropertySettings()
	 * @method \string getPropertyValue()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setPropertyValue(\string|\Bitrix\Main\DB\SqlExpression $propertyValue)
	 * @method bool hasPropertyValue()
	 * @method bool isPropertyValueFilled()
	 * @method bool isPropertyValueChanged()
	 * @method \string remindActualPropertyValue()
	 * @method \string requirePropertyValue()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetPropertyValue()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetPropertyValue()
	 * @method \string fillPropertyValue()
	 * @method \Bitrix\Main\Type\DateTime getCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setCreatedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdDate)
	 * @method bool hasCreatedDate()
	 * @method bool isCreatedDateFilled()
	 * @method bool isCreatedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetCreatedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetCreatedDate()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedDate()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetCreatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \string getVisibility()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setVisibility(\string|\Bitrix\Main\DB\SqlExpression $visibility)
	 * @method bool hasVisibility()
	 * @method bool isVisibilityFilled()
	 * @method bool isVisibilityChanged()
	 * @method \string remindActualVisibility()
	 * @method \string requireVisibility()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetVisibility()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetVisibility()
	 * @method \string fillVisibility()
	 * @method \Bitrix\Main\Type\DateTime getModifiedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setModifiedDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modifiedDate)
	 * @method bool hasModifiedDate()
	 * @method bool isModifiedDateFilled()
	 * @method bool isModifiedDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime requireModifiedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetModifiedDate()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetModifiedDate()
	 * @method \Bitrix\Main\Type\DateTime fillModifiedDate()
	 * @method \int getModifiedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar setModifiedBy(\int|\Bitrix\Main\DB\SqlExpression $modifiedBy)
	 * @method bool hasModifiedBy()
	 * @method bool isModifiedByFilled()
	 * @method bool isModifiedByChanged()
	 * @method \int remindActualModifiedBy()
	 * @method \int requireModifiedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar resetModifiedBy()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unsetModifiedBy()
	 * @method \int fillModifiedBy()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar wakeUp($data)
	 */
	class EO_GlobalVar extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Type\Entity\GlobalVarTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Type\Entity\GlobalVarTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Type\Entity {
	/**
	 * EO_GlobalVar_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIdList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \string[] getPropertyTypeList()
	 * @method \string[] fillPropertyType()
	 * @method \boolean[] getIsRequiredList()
	 * @method \boolean[] fillIsRequired()
	 * @method \boolean[] getIsMultipleList()
	 * @method \boolean[] fillIsMultiple()
	 * @method \string[] getPropertyOptionsList()
	 * @method \string[] fillPropertyOptions()
	 * @method \string[] getPropertySettingsList()
	 * @method \string[] fillPropertySettings()
	 * @method \string[] getPropertyValueList()
	 * @method \string[] fillPropertyValue()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedDate()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \string[] getVisibilityList()
	 * @method \string[] fillVisibility()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModifiedDate()
	 * @method \int[] getModifiedByList()
	 * @method \int[] fillModifiedBy()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection merge(?\Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection filter(callable $callback)
	 */
	class EO_GlobalVar_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Type\Entity\GlobalVarTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Type\Entity\GlobalVarTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Type\Entity {
	/**
	 * @method static EO_GlobalVar_Query query()
	 * @method static EO_GlobalVar_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_GlobalVar_Result getById($id)
	 * @method static EO_GlobalVar_Result getList(array $parameters = [])
	 * @method static EO_GlobalVar_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection wakeUpCollection($rows)
	 */
	class GlobalVarTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_GlobalVar_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection fetchCollection()
	 */
	class EO_GlobalVar_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection fetchCollection()
	 */
	class EO_GlobalVar_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Type\Entity\EO_GlobalVar_Collection wakeUpCollection($rows)
	 */
	class EO_GlobalVar_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowDurationStat
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat resetWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getDuration()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat setDuration(\int|\Bitrix\Main\DB\SqlExpression $duration)
	 * @method bool hasDuration()
	 * @method bool isDurationFilled()
	 * @method bool isDurationChanged()
	 * @method \int remindActualDuration()
	 * @method \int requireDuration()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat resetDuration()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat unsetDuration()
	 * @method \int fillDuration()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat wakeUp($data)
	 */
	class EO_WorkflowDurationStat extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowDurationStat_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getDurationList()
	 * @method \int[] fillDuration()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection filter(callable $callback)
	 */
	class EO_WorkflowDurationStat_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowDurationStatTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowDurationStat_Query query()
	 * @method static EO_WorkflowDurationStat_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowDurationStat_Result getById($id)
	 * @method static EO_WorkflowDurationStat_Result getList(array $parameters = [])
	 * @method static EO_WorkflowDurationStat_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection wakeUpCollection($rows)
	 */
	class WorkflowDurationStatTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowDurationStat_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection fetchCollection()
	 */
	class EO_WorkflowDurationStat_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection fetchCollection()
	 */
	class EO_WorkflowDurationStat_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowDurationStat_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowDurationStat_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowMetadata
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata resetWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \int getStartDuration()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata setStartDuration(\int|\Bitrix\Main\DB\SqlExpression $startDuration)
	 * @method bool hasStartDuration()
	 * @method bool isStartDurationFilled()
	 * @method bool isStartDurationChanged()
	 * @method \int remindActualStartDuration()
	 * @method \int requireStartDuration()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata resetStartDuration()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata unsetStartDuration()
	 * @method \int fillStartDuration()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata wakeUp($data)
	 */
	class EO_WorkflowMetadata extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowMetadata_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \int[] getStartDurationList()
	 * @method \int[] fillStartDuration()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection filter(callable $callback)
	 */
	class EO_WorkflowMetadata_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowMetadataTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowMetadata_Query query()
	 * @method static EO_WorkflowMetadata_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowMetadata_Result getById($id)
	 * @method static EO_WorkflowMetadata_Result getList(array $parameters = [])
	 * @method static EO_WorkflowMetadata_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection wakeUpCollection($rows)
	 */
	class WorkflowMetadataTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowMetadata_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection fetchCollection()
	 */
	class EO_WorkflowMetadata_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection fetchCollection()
	 */
	class EO_WorkflowMetadata_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowMetadata_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowFilter
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter resetModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter resetEntity()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter resetDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \Bitrix\Main\Type\DateTime getStarted()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter setStarted(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $started)
	 * @method bool hasStarted()
	 * @method bool isStartedFilled()
	 * @method bool isStartedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStarted()
	 * @method \Bitrix\Main\Type\DateTime requireStarted()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter resetStarted()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter unsetStarted()
	 * @method \Bitrix\Main\Type\DateTime fillStarted()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter wakeUp($data)
	 */
	class EO_WorkflowFilter extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowFilter_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \Bitrix\Main\Type\DateTime[] getStartedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStarted()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection filter(callable $callback)
	 */
	class EO_WorkflowFilter_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowFilterTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowFilter_Query query()
	 * @method static EO_WorkflowFilter_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowFilter_Result getById($id)
	 * @method static EO_WorkflowFilter_Result getList(array $parameters = [])
	 * @method static EO_WorkflowFilter_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection wakeUpCollection($rows)
	 */
	class WorkflowFilterTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowFilter_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection fetchCollection()
	 */
	class EO_WorkflowFilter_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection fetchCollection()
	 */
	class EO_WorkflowFilter_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowFilter_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowInstance
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setId(\string|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetModuleId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getEntity()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setEntity(\string|\Bitrix\Main\DB\SqlExpression $entity)
	 * @method bool hasEntity()
	 * @method bool isEntityFilled()
	 * @method bool isEntityChanged()
	 * @method \string remindActualEntity()
	 * @method \string requireEntity()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetEntity()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetEntity()
	 * @method \string fillEntity()
	 * @method \string getDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setDocumentId(\string|\Bitrix\Main\DB\SqlExpression $documentId)
	 * @method bool hasDocumentId()
	 * @method bool isDocumentIdFilled()
	 * @method bool isDocumentIdChanged()
	 * @method \string remindActualDocumentId()
	 * @method \string requireDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetDocumentId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetDocumentId()
	 * @method \string fillDocumentId()
	 * @method \int getWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setWorkflowTemplateId(\int|\Bitrix\Main\DB\SqlExpression $workflowTemplateId)
	 * @method bool hasWorkflowTemplateId()
	 * @method bool isWorkflowTemplateIdFilled()
	 * @method bool isWorkflowTemplateIdChanged()
	 * @method \int remindActualWorkflowTemplateId()
	 * @method \int requireWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetWorkflowTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetWorkflowTemplateId()
	 * @method \int fillWorkflowTemplateId()
	 * @method \string getWorkflow()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setWorkflow(\string|\Bitrix\Main\DB\SqlExpression $workflow)
	 * @method bool hasWorkflow()
	 * @method bool isWorkflowFilled()
	 * @method bool isWorkflowChanged()
	 * @method \string remindActualWorkflow()
	 * @method \string requireWorkflow()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetWorkflow()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetWorkflow()
	 * @method \string fillWorkflow()
	 * @method \string getWorkflowRo()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setWorkflowRo(\string|\Bitrix\Main\DB\SqlExpression $workflowRo)
	 * @method bool hasWorkflowRo()
	 * @method bool isWorkflowRoFilled()
	 * @method bool isWorkflowRoChanged()
	 * @method \string remindActualWorkflowRo()
	 * @method \string requireWorkflowRo()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetWorkflowRo()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetWorkflowRo()
	 * @method \string fillWorkflowRo()
	 * @method \Bitrix\Main\Type\DateTime getStarted()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setStarted(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $started)
	 * @method bool hasStarted()
	 * @method bool isStartedFilled()
	 * @method bool isStartedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStarted()
	 * @method \Bitrix\Main\Type\DateTime requireStarted()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetStarted()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetStarted()
	 * @method \Bitrix\Main\Type\DateTime fillStarted()
	 * @method \int getStartedBy()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setStartedBy(\int|\Bitrix\Main\DB\SqlExpression $startedBy)
	 * @method bool hasStartedBy()
	 * @method bool isStartedByFilled()
	 * @method bool isStartedByChanged()
	 * @method \int remindActualStartedBy()
	 * @method \int requireStartedBy()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetStartedBy()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetStartedBy()
	 * @method \int fillStartedBy()
	 * @method \Bitrix\Main\EO_User getStartedUser()
	 * @method \Bitrix\Main\EO_User remindActualStartedUser()
	 * @method \Bitrix\Main\EO_User requireStartedUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setStartedUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetStartedUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetStartedUser()
	 * @method bool hasStartedUser()
	 * @method bool isStartedUserFilled()
	 * @method bool isStartedUserChanged()
	 * @method \Bitrix\Main\EO_User fillStartedUser()
	 * @method \int getStartedEventType()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setStartedEventType(\int|\Bitrix\Main\DB\SqlExpression $startedEventType)
	 * @method bool hasStartedEventType()
	 * @method bool isStartedEventTypeFilled()
	 * @method bool isStartedEventTypeChanged()
	 * @method \int remindActualStartedEventType()
	 * @method \int requireStartedEventType()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetStartedEventType()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetStartedEventType()
	 * @method \int fillStartedEventType()
	 * @method \int getStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setStatus(\int|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \int remindActualStatus()
	 * @method \int requireStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetStatus()
	 * @method \int fillStatus()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method \string getOwnerId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setOwnerId(\string|\Bitrix\Main\DB\SqlExpression $ownerId)
	 * @method bool hasOwnerId()
	 * @method bool isOwnerIdFilled()
	 * @method bool isOwnerIdChanged()
	 * @method \string remindActualOwnerId()
	 * @method \string requireOwnerId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetOwnerId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetOwnerId()
	 * @method \string fillOwnerId()
	 * @method \Bitrix\Main\Type\DateTime getOwnedUntil()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setOwnedUntil(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $ownedUntil)
	 * @method bool hasOwnedUntil()
	 * @method bool isOwnedUntilFilled()
	 * @method bool isOwnedUntilChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualOwnedUntil()
	 * @method \Bitrix\Main\Type\DateTime requireOwnedUntil()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetOwnedUntil()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetOwnedUntil()
	 * @method \Bitrix\Main\Type\DateTime fillOwnedUntil()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState getState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState remindActualState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState requireState()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setState(\Bitrix\Bizproc\Workflow\WorkflowState $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetState()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetState()
	 * @method bool hasState()
	 * @method bool isStateFilled()
	 * @method bool isStateChanged()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState fillState()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance wakeUp($data)
	 */
	class EO_WorkflowInstance extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowInstance_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIdList()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getEntityList()
	 * @method \string[] fillEntity()
	 * @method \string[] getDocumentIdList()
	 * @method \string[] fillDocumentId()
	 * @method \int[] getWorkflowTemplateIdList()
	 * @method \int[] fillWorkflowTemplateId()
	 * @method \string[] getWorkflowList()
	 * @method \string[] fillWorkflow()
	 * @method \string[] getWorkflowRoList()
	 * @method \string[] fillWorkflowRo()
	 * @method \Bitrix\Main\Type\DateTime[] getStartedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStarted()
	 * @method \int[] getStartedByList()
	 * @method \int[] fillStartedBy()
	 * @method \Bitrix\Main\EO_User[] getStartedUserList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection getStartedUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillStartedUser()
	 * @method \int[] getStartedEventTypeList()
	 * @method \int[] fillStartedEventType()
	 * @method \int[] getStatusList()
	 * @method \int[] fillStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \string[] getOwnerIdList()
	 * @method \string[] fillOwnerId()
	 * @method \Bitrix\Main\Type\DateTime[] getOwnedUntilList()
	 * @method \Bitrix\Main\Type\DateTime[] fillOwnedUntil()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState[] getStateList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection getStateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection fillState()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection filter(callable $callback)
	 */
	class EO_WorkflowInstance_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowInstance_Query query()
	 * @method static EO_WorkflowInstance_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowInstance_Result getById($id)
	 * @method static EO_WorkflowInstance_Result getList(array $parameters = [])
	 * @method static EO_WorkflowInstance_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection wakeUpCollection($rows)
	 */
	class WorkflowInstanceTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowInstance_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection fetchCollection()
	 */
	class EO_WorkflowInstance_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection fetchCollection()
	 */
	class EO_WorkflowInstance_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowInstance_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowInstance_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowUser
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \int getIsAuthor()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setIsAuthor(\int|\Bitrix\Main\DB\SqlExpression $isAuthor)
	 * @method bool hasIsAuthor()
	 * @method bool isIsAuthorFilled()
	 * @method bool isIsAuthorChanged()
	 * @method \int remindActualIsAuthor()
	 * @method \int requireIsAuthor()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetIsAuthor()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetIsAuthor()
	 * @method \int fillIsAuthor()
	 * @method \int getWorkflowStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setWorkflowStatus(\int|\Bitrix\Main\DB\SqlExpression $workflowStatus)
	 * @method bool hasWorkflowStatus()
	 * @method bool isWorkflowStatusFilled()
	 * @method bool isWorkflowStatusChanged()
	 * @method \int remindActualWorkflowStatus()
	 * @method \int requireWorkflowStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetWorkflowStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetWorkflowStatus()
	 * @method \int fillWorkflowStatus()
	 * @method \int getTaskStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setTaskStatus(\int|\Bitrix\Main\DB\SqlExpression $taskStatus)
	 * @method bool hasTaskStatus()
	 * @method bool isTaskStatusFilled()
	 * @method bool isTaskStatusChanged()
	 * @method \int remindActualTaskStatus()
	 * @method \int requireTaskStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetTaskStatus()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetTaskStatus()
	 * @method \int fillTaskStatus()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter getFilter()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter remindActualFilter()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter requireFilter()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setFilter(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetFilter()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetFilter()
	 * @method bool hasFilter()
	 * @method bool isFilterFilled()
	 * @method bool isFilterChanged()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter fillFilter()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment getComments()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment remindActualComments()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment requireComments()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setComments(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetComments()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetComments()
	 * @method bool hasComments()
	 * @method bool isCommentsFilled()
	 * @method bool isCommentsChanged()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment fillComments()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState getWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState remindActualWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState requireWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setWorkflowState(\Bitrix\Bizproc\Workflow\WorkflowState $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetWorkflowState()
	 * @method bool hasWorkflowState()
	 * @method bool isWorkflowStateFilled()
	 * @method bool isWorkflowStateChanged()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState fillWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent getSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent remindActualSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent requireSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser setSearchContent(\Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser resetSearchContent()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unsetSearchContent()
	 * @method bool hasSearchContent()
	 * @method bool isSearchContentFilled()
	 * @method bool isSearchContentChanged()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent fillSearchContent()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser wakeUp($data)
	 */
	class EO_WorkflowUser extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowUser_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getUserIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \int[] getIsAuthorList()
	 * @method \int[] fillIsAuthor()
	 * @method \int[] getWorkflowStatusList()
	 * @method \int[] fillWorkflowStatus()
	 * @method \int[] getTaskStatusList()
	 * @method \int[] fillTaskStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter[] getFilterList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection getFilterCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowFilter_Collection fillFilter()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment[] getCommentsList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection getCommentsCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection fillComments()
	 * @method \Bitrix\Bizproc\Workflow\WorkflowState[] getWorkflowStateList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection getWorkflowStateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowState_Collection fillWorkflowState()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent[] getSearchContentList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection getSearchContentCollection()
	 * @method \Bitrix\Bizproc\Workflow\Task\EO_TaskSearchContent_Collection fillSearchContent()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection filter(callable $callback)
	 */
	class EO_WorkflowUser_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowUser_Query query()
	 * @method static EO_WorkflowUser_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowUser_Result getById($id)
	 * @method static EO_WorkflowUser_Result getList(array $parameters = [])
	 * @method static EO_WorkflowUser_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection wakeUpCollection($rows)
	 */
	class WorkflowUserTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowUser_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection fetchCollection()
	 */
	class EO_WorkflowUser_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection fetchCollection()
	 */
	class EO_WorkflowUser_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowUser_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable */
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowUserComment
	 * @see \Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \int getUnreadCnt()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment setUnreadCnt(\int|\Bitrix\Main\DB\SqlExpression $unreadCnt)
	 * @method bool hasUnreadCnt()
	 * @method bool isUnreadCntFilled()
	 * @method bool isUnreadCntChanged()
	 * @method \int remindActualUnreadCnt()
	 * @method \int requireUnreadCnt()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment resetUnreadCnt()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment unsetUnreadCnt()
	 * @method \int fillUnreadCnt()
	 * @method \int getLastType()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment setLastType(\int|\Bitrix\Main\DB\SqlExpression $lastType)
	 * @method bool hasLastType()
	 * @method bool isLastTypeFilled()
	 * @method bool isLastTypeChanged()
	 * @method \int remindActualLastType()
	 * @method \int requireLastType()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment resetLastType()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment unsetLastType()
	 * @method \int fillLastType()
	 * @method \Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment setModified(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualModified()
	 * @method \Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment unsetModified()
	 * @method \Bitrix\Main\Type\DateTime fillModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser getWorkflowUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser remindActualWorkflowUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser requireWorkflowUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment setWorkflowUser(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser $object)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment resetWorkflowUser()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment unsetWorkflowUser()
	 * @method bool hasWorkflowUser()
	 * @method bool isWorkflowUserFilled()
	 * @method bool isWorkflowUserChanged()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser fillWorkflowUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment wakeUp($data)
	 */
	class EO_WorkflowUserComment extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * EO_WorkflowUserComment_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getUserIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \int[] getUnreadCntList()
	 * @method \int[] fillUnreadCnt()
	 * @method \int[] getLastTypeList()
	 * @method \int[] fillLastType()
	 * @method \Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser[] getWorkflowUserList()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection getWorkflowUserCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUser_Collection fillWorkflowUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection merge(?\Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection filter(callable $callback)
	 */
	class EO_WorkflowUserComment_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Entity\WorkflowUserCommentTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Entity {
	/**
	 * @method static EO_WorkflowUserComment_Query query()
	 * @method static EO_WorkflowUserComment_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowUserComment_Result getById($id)
	 * @method static EO_WorkflowUserComment_Result getList(array $parameters = [])
	 * @method static EO_WorkflowUserComment_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection wakeUpCollection($rows)
	 */
	class WorkflowUserCommentTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowUserComment_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection fetchCollection()
	 */
	class EO_WorkflowUserComment_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection fetchCollection()
	 */
	class EO_WorkflowUserComment_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Entity\EO_WorkflowUserComment_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowUserComment_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\SchedulerEventTable */
namespace Bitrix\Bizproc {
	/**
	 * EO_SchedulerEvent
	 * @see \Bitrix\Bizproc\SchedulerEventTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWorkflowId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setWorkflowId(\string|\Bitrix\Main\DB\SqlExpression $workflowId)
	 * @method bool hasWorkflowId()
	 * @method bool isWorkflowIdFilled()
	 * @method bool isWorkflowIdChanged()
	 * @method \string remindActualWorkflowId()
	 * @method \string requireWorkflowId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent resetWorkflowId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unsetWorkflowId()
	 * @method \string fillWorkflowId()
	 * @method \string getHandler()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setHandler(\string|\Bitrix\Main\DB\SqlExpression $handler)
	 * @method bool hasHandler()
	 * @method bool isHandlerFilled()
	 * @method bool isHandlerChanged()
	 * @method \string remindActualHandler()
	 * @method \string requireHandler()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent resetHandler()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unsetHandler()
	 * @method \string fillHandler()
	 * @method \string getEventModule()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setEventModule(\string|\Bitrix\Main\DB\SqlExpression $eventModule)
	 * @method bool hasEventModule()
	 * @method bool isEventModuleFilled()
	 * @method bool isEventModuleChanged()
	 * @method \string remindActualEventModule()
	 * @method \string requireEventModule()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent resetEventModule()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unsetEventModule()
	 * @method \string fillEventModule()
	 * @method \string getEventType()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setEventType(\string|\Bitrix\Main\DB\SqlExpression $eventType)
	 * @method bool hasEventType()
	 * @method bool isEventTypeFilled()
	 * @method bool isEventTypeChanged()
	 * @method \string remindActualEventType()
	 * @method \string requireEventType()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent resetEventType()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unsetEventType()
	 * @method \string fillEventType()
	 * @method \string getEntityId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setEntityId(\string|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \string remindActualEntityId()
	 * @method \string requireEntityId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent resetEntityId()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unsetEntityId()
	 * @method \string fillEntityId()
	 * @method \string getEventParameters()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent setEventParameters(\string|\Bitrix\Main\DB\SqlExpression $eventParameters)
	 * @method bool hasEventParameters()
	 * @method bool isEventParametersFilled()
	 * @method bool isEventParametersChanged()
	 * @method \string remindActualEventParameters()
	 * @method \string requireEventParameters()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent resetEventParameters()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unsetEventParameters()
	 * @method \string fillEventParameters()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent set($fieldName, $value)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent reset($fieldName)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\EO_SchedulerEvent wakeUp($data)
	 */
	class EO_SchedulerEvent extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\SchedulerEventTable */
		static public $dataClass = '\Bitrix\Bizproc\SchedulerEventTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc {
	/**
	 * EO_SchedulerEvent_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWorkflowIdList()
	 * @method \string[] fillWorkflowId()
	 * @method \string[] getHandlerList()
	 * @method \string[] fillHandler()
	 * @method \string[] getEventModuleList()
	 * @method \string[] fillEventModule()
	 * @method \string[] getEventTypeList()
	 * @method \string[] fillEventType()
	 * @method \string[] getEntityIdList()
	 * @method \string[] fillEntityId()
	 * @method \string[] getEventParametersList()
	 * @method \string[] fillEventParameters()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\EO_SchedulerEvent $object)
	 * @method bool has(\Bitrix\Bizproc\EO_SchedulerEvent $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent getByPrimary($primary)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\EO_SchedulerEvent $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\EO_SchedulerEvent_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent_Collection merge(?\Bitrix\Bizproc\EO_SchedulerEvent_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent|null find(callable $callback)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent_Collection filter(callable $callback)
	 */
	class EO_SchedulerEvent_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\SchedulerEventTable */
		static public $dataClass = '\Bitrix\Bizproc\SchedulerEventTable';
	}
}
namespace Bitrix\Bizproc {
	/**
	 * @method static EO_SchedulerEvent_Query query()
	 * @method static EO_SchedulerEvent_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_SchedulerEvent_Result getById($id)
	 * @method static EO_SchedulerEvent_Result getList(array $parameters = [])
	 * @method static EO_SchedulerEvent_Entity getEntity()
	 * @method static \Bitrix\Bizproc\EO_SchedulerEvent createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\EO_SchedulerEvent_Collection createCollection()
	 * @method static \Bitrix\Bizproc\EO_SchedulerEvent wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\EO_SchedulerEvent_Collection wakeUpCollection($rows)
	 */
	class SchedulerEventTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_SchedulerEvent_Result exec()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent fetchObject()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent_Collection fetchCollection()
	 */
	class EO_SchedulerEvent_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent fetchObject()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent_Collection fetchCollection()
	 */
	class EO_SchedulerEvent_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent_Collection createCollection()
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent wakeUpObject($row)
	 * @method \Bitrix\Bizproc\EO_SchedulerEvent_Collection wakeUpCollection($rows)
	 */
	class EO_SchedulerEvent_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable */
namespace Bitrix\Bizproc\Workflow\Template\Note\Entity {
	/**
	 * EO_TemplateNote
	 * @see \Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAuthorId(\int|\Bitrix\Main\DB\SqlExpression $authorId)
	 * @method bool hasAuthorId()
	 * @method bool isAuthorIdFilled()
	 * @method bool isAuthorIdChanged()
	 * @method \int remindActualAuthorId()
	 * @method \int requireAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAuthorId()
	 * @method \int fillAuthorId()
	 * @method \string getAnchorType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAnchorType(\string|\Bitrix\Main\DB\SqlExpression $anchorType)
	 * @method bool hasAnchorType()
	 * @method bool isAnchorTypeFilled()
	 * @method bool isAnchorTypeChanged()
	 * @method \string remindActualAnchorType()
	 * @method \string requireAnchorType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAnchorType()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAnchorType()
	 * @method \string fillAnchorType()
	 * @method null|\string getAnchorNodeId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAnchorNodeId(null|\string|\Bitrix\Main\DB\SqlExpression $anchorNodeId)
	 * @method bool hasAnchorNodeId()
	 * @method bool isAnchorNodeIdFilled()
	 * @method bool isAnchorNodeIdChanged()
	 * @method null|\string remindActualAnchorNodeId()
	 * @method null|\string requireAnchorNodeId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAnchorNodeId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAnchorNodeId()
	 * @method null|\string fillAnchorNodeId()
	 * @method null|\string getAnchorSourceBlockId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAnchorSourceBlockId(null|\string|\Bitrix\Main\DB\SqlExpression $anchorSourceBlockId)
	 * @method bool hasAnchorSourceBlockId()
	 * @method bool isAnchorSourceBlockIdFilled()
	 * @method bool isAnchorSourceBlockIdChanged()
	 * @method null|\string remindActualAnchorSourceBlockId()
	 * @method null|\string requireAnchorSourceBlockId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAnchorSourceBlockId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAnchorSourceBlockId()
	 * @method null|\string fillAnchorSourceBlockId()
	 * @method null|\string getAnchorSourcePortId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAnchorSourcePortId(null|\string|\Bitrix\Main\DB\SqlExpression $anchorSourcePortId)
	 * @method bool hasAnchorSourcePortId()
	 * @method bool isAnchorSourcePortIdFilled()
	 * @method bool isAnchorSourcePortIdChanged()
	 * @method null|\string remindActualAnchorSourcePortId()
	 * @method null|\string requireAnchorSourcePortId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAnchorSourcePortId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAnchorSourcePortId()
	 * @method null|\string fillAnchorSourcePortId()
	 * @method null|\string getAnchorTargetBlockId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAnchorTargetBlockId(null|\string|\Bitrix\Main\DB\SqlExpression $anchorTargetBlockId)
	 * @method bool hasAnchorTargetBlockId()
	 * @method bool isAnchorTargetBlockIdFilled()
	 * @method bool isAnchorTargetBlockIdChanged()
	 * @method null|\string remindActualAnchorTargetBlockId()
	 * @method null|\string requireAnchorTargetBlockId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAnchorTargetBlockId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAnchorTargetBlockId()
	 * @method null|\string fillAnchorTargetBlockId()
	 * @method null|\string getAnchorTargetPortId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setAnchorTargetPortId(null|\string|\Bitrix\Main\DB\SqlExpression $anchorTargetPortId)
	 * @method bool hasAnchorTargetPortId()
	 * @method bool isAnchorTargetPortIdFilled()
	 * @method bool isAnchorTargetPortIdChanged()
	 * @method null|\string remindActualAnchorTargetPortId()
	 * @method null|\string requireAnchorTargetPortId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetAnchorTargetPortId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetAnchorTargetPortId()
	 * @method null|\string fillAnchorTargetPortId()
	 * @method \int getOffsetX()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setOffsetX(\int|\Bitrix\Main\DB\SqlExpression $offsetX)
	 * @method bool hasOffsetX()
	 * @method bool isOffsetXFilled()
	 * @method bool isOffsetXChanged()
	 * @method \int remindActualOffsetX()
	 * @method \int requireOffsetX()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetOffsetX()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetOffsetX()
	 * @method \int fillOffsetX()
	 * @method \int getOffsetY()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setOffsetY(\int|\Bitrix\Main\DB\SqlExpression $offsetY)
	 * @method bool hasOffsetY()
	 * @method bool isOffsetYFilled()
	 * @method bool isOffsetYChanged()
	 * @method \int remindActualOffsetY()
	 * @method \int requireOffsetY()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetOffsetY()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetOffsetY()
	 * @method \int fillOffsetY()
	 * @method \int getPositionX()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setPositionX(\int|\Bitrix\Main\DB\SqlExpression $positionX)
	 * @method bool hasPositionX()
	 * @method bool isPositionXFilled()
	 * @method bool isPositionXChanged()
	 * @method \int remindActualPositionX()
	 * @method \int requirePositionX()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetPositionX()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetPositionX()
	 * @method \int fillPositionX()
	 * @method \int getPositionY()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setPositionY(\int|\Bitrix\Main\DB\SqlExpression $positionY)
	 * @method bool hasPositionY()
	 * @method bool isPositionYFilled()
	 * @method bool isPositionYChanged()
	 * @method \int remindActualPositionY()
	 * @method \int requirePositionY()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetPositionY()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetPositionY()
	 * @method \int fillPositionY()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl getTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl remindActualTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl requireTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote setTemplate(\Bitrix\Bizproc\Workflow\Template\Tpl $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote resetTemplate()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unsetTemplate()
	 * @method bool hasTemplate()
	 * @method bool isTemplateFilled()
	 * @method bool isTemplateChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote wakeUp($data)
	 */
	class EO_TemplateNote extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Note\Entity {
	/**
	 * EO_TemplateNote_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getAuthorIdList()
	 * @method \int[] fillAuthorId()
	 * @method \string[] getAnchorTypeList()
	 * @method \string[] fillAnchorType()
	 * @method null|\string[] getAnchorNodeIdList()
	 * @method null|\string[] fillAnchorNodeId()
	 * @method null|\string[] getAnchorSourceBlockIdList()
	 * @method null|\string[] fillAnchorSourceBlockId()
	 * @method null|\string[] getAnchorSourcePortIdList()
	 * @method null|\string[] fillAnchorSourcePortId()
	 * @method null|\string[] getAnchorTargetBlockIdList()
	 * @method null|\string[] fillAnchorTargetBlockId()
	 * @method null|\string[] getAnchorTargetPortIdList()
	 * @method null|\string[] fillAnchorTargetPortId()
	 * @method \int[] getOffsetXList()
	 * @method \int[] fillOffsetX()
	 * @method \int[] getOffsetYList()
	 * @method \int[] fillOffsetY()
	 * @method \int[] getPositionXList()
	 * @method \int[] fillPositionX()
	 * @method \int[] getPositionYList()
	 * @method \int[] fillPositionY()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Tpl[] getTemplateList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection getTemplateCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection fillTemplate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection filter(callable $callback)
	 */
	class EO_TemplateNote_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Note\Entity {
	/**
	 * @method static EO_TemplateNote_Query query()
	 * @method static EO_TemplateNote_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TemplateNote_Result getById($id)
	 * @method static EO_TemplateNote_Result getList(array $parameters = [])
	 * @method static EO_TemplateNote_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection wakeUpCollection($rows)
	 */
	class TemplateNoteTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TemplateNote_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection fetchCollection()
	 */
	class EO_TemplateNote_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection fetchCollection()
	 */
	class EO_TemplateNote_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection wakeUpCollection($rows)
	 */
	class EO_TemplateNote_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable */
namespace Bitrix\Bizproc\Workflow\Template\Note\Entity {
	/**
	 * EO_TemplateNoteMessage
	 * @see \Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getNoteId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setNoteId(\int|\Bitrix\Main\DB\SqlExpression $noteId)
	 * @method bool hasNoteId()
	 * @method bool isNoteIdFilled()
	 * @method bool isNoteIdChanged()
	 * @method \int remindActualNoteId()
	 * @method \int requireNoteId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetNoteId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetNoteId()
	 * @method \int fillNoteId()
	 * @method \int getAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setAuthorId(\int|\Bitrix\Main\DB\SqlExpression $authorId)
	 * @method bool hasAuthorId()
	 * @method bool isAuthorIdFilled()
	 * @method bool isAuthorIdChanged()
	 * @method \int remindActualAuthorId()
	 * @method \int requireAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetAuthorId()
	 * @method \int fillAuthorId()
	 * @method \string getMessage()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setMessage(\string|\Bitrix\Main\DB\SqlExpression $message)
	 * @method bool hasMessage()
	 * @method bool isMessageFilled()
	 * @method bool isMessageChanged()
	 * @method \string remindActualMessage()
	 * @method \string requireMessage()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetMessage()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetMessage()
	 * @method \string fillMessage()
	 * @method \int getRevision()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setRevision(\int|\Bitrix\Main\DB\SqlExpression $revision)
	 * @method bool hasRevision()
	 * @method bool isRevisionFilled()
	 * @method bool isRevisionChanged()
	 * @method \int remindActualRevision()
	 * @method \int requireRevision()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetRevision()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetRevision()
	 * @method \int fillRevision()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 * @method null|\Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setModified(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualModified()
	 * @method null|\Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetModified()
	 * @method null|\Bitrix\Main\Type\DateTime fillModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote getNote()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote remindActualNote()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote requireNote()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage setNote(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage resetNote()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unsetNote()
	 * @method bool hasNote()
	 * @method bool isNoteFilled()
	 * @method bool isNoteChanged()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote fillNote()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage wakeUp($data)
	 */
	class EO_TemplateNoteMessage extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Note\Entity {
	/**
	 * EO_TemplateNoteMessage_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getNoteIdList()
	 * @method \int[] fillNoteId()
	 * @method \int[] getAuthorIdList()
	 * @method \int[] fillAuthorId()
	 * @method \string[] getMessageList()
	 * @method \string[] fillMessage()
	 * @method \int[] getRevisionList()
	 * @method \int[] fillRevision()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 * @method null|\Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote[] getNoteList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection getNoteCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote_Collection fillNote()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection filter(callable $callback)
	 */
	class EO_TemplateNoteMessage_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Note\Entity {
	/**
	 * @method static EO_TemplateNoteMessage_Query query()
	 * @method static EO_TemplateNoteMessage_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TemplateNoteMessage_Result getById($id)
	 * @method static EO_TemplateNoteMessage_Result getList(array $parameters = [])
	 * @method static EO_TemplateNoteMessage_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection wakeUpCollection($rows)
	 */
	class TemplateNoteMessageTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TemplateNoteMessage_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection fetchCollection()
	 */
	class EO_TemplateNoteMessage_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection fetchCollection()
	 */
	class EO_TemplateNoteMessage_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage_Collection wakeUpCollection($rows)
	 */
	class EO_TemplateNoteMessage_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable:bizproc/lib/Workflow/Template/workflowtemplatelocktable.php */
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateLock
	 * @see \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \int remindActualTemplateId()
	 * @method \int requireTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock resetTemplateId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock unsetTemplateId()
	 * @method \int fillTemplateId()
	 * @method \int getUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock resetUserId()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getAcquiredAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock setAcquiredAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $acquiredAt)
	 * @method bool hasAcquiredAt()
	 * @method bool isAcquiredAtFilled()
	 * @method bool isAcquiredAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualAcquiredAt()
	 * @method \Bitrix\Main\Type\DateTime requireAcquiredAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock resetAcquiredAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock unsetAcquiredAt()
	 * @method \Bitrix\Main\Type\DateTime fillAcquiredAt()
	 * @method \Bitrix\Main\Type\DateTime getExpiresAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock setExpiresAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $expiresAt)
	 * @method bool hasExpiresAt()
	 * @method bool isExpiresAtFilled()
	 * @method bool isExpiresAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualExpiresAt()
	 * @method \Bitrix\Main\Type\DateTime requireExpiresAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock resetExpiresAt()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock unsetExpiresAt()
	 * @method \Bitrix\Main\Type\DateTime fillExpiresAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock wakeUp($data)
	 */
	class EO_WorkflowTemplateLock extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * EO_WorkflowTemplateLock_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTemplateIdList()
	 * @method \int[] fillTemplateId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getAcquiredAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillAcquiredAt()
	 * @method \Bitrix\Main\Type\DateTime[] getExpiresAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillExpiresAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection merge(?\Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection filter(callable $callback)
	 */
	class EO_WorkflowTemplateLock_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowTemplateLock_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateLock_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection fetchCollection()
	 */
	class EO_WorkflowTemplateLock_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateLock_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowTemplateLock_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable */
namespace Bitrix\Bizproc\Workflow\Template\Fragment\Entity {
	/**
	 * EO_TemplateFragment
	 * @see \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetName()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetName()
	 * @method \string fillName()
	 * @method \int getAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setAuthorId(\int|\Bitrix\Main\DB\SqlExpression $authorId)
	 * @method bool hasAuthorId()
	 * @method bool isAuthorIdFilled()
	 * @method bool isAuthorIdChanged()
	 * @method \int remindActualAuthorId()
	 * @method \int requireAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetAuthorId()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetAuthorId()
	 * @method \int fillAuthorId()
	 * @method \string getIsShared()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setIsShared(\string|\Bitrix\Main\DB\SqlExpression $isShared)
	 * @method bool hasIsShared()
	 * @method bool isIsSharedFilled()
	 * @method bool isIsSharedChanged()
	 * @method \string remindActualIsShared()
	 * @method \string requireIsShared()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetIsShared()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetIsShared()
	 * @method \string fillIsShared()
	 * @method \int getNodeCount()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setNodeCount(\int|\Bitrix\Main\DB\SqlExpression $nodeCount)
	 * @method bool hasNodeCount()
	 * @method bool isNodeCountFilled()
	 * @method bool isNodeCountChanged()
	 * @method \int remindActualNodeCount()
	 * @method \int requireNodeCount()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetNodeCount()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetNodeCount()
	 * @method \int fillNodeCount()
	 * @method \string getFragment()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setFragment(\string|\Bitrix\Main\DB\SqlExpression $fragment)
	 * @method bool hasFragment()
	 * @method bool isFragmentFilled()
	 * @method bool isFragmentChanged()
	 * @method \string remindActualFragment()
	 * @method \string requireFragment()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetFragment()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetFragment()
	 * @method \string fillFragment()
	 * @method null|\int getUpdatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setUpdatedBy(null|\int|\Bitrix\Main\DB\SqlExpression $updatedBy)
	 * @method bool hasUpdatedBy()
	 * @method bool isUpdatedByFilled()
	 * @method bool isUpdatedByChanged()
	 * @method null|\int remindActualUpdatedBy()
	 * @method null|\int requireUpdatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetUpdatedBy()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetUpdatedBy()
	 * @method null|\int fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setCreated(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $created)
	 * @method bool hasCreated()
	 * @method bool isCreatedFilled()
	 * @method bool isCreatedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreated()
	 * @method \Bitrix\Main\Type\DateTime requireCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetCreated()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetCreated()
	 * @method \Bitrix\Main\Type\DateTime fillCreated()
	 * @method null|\Bitrix\Main\Type\DateTime getModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setModified(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $modified)
	 * @method bool hasModified()
	 * @method bool isModifiedFilled()
	 * @method bool isModifiedChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualModified()
	 * @method null|\Bitrix\Main\Type\DateTime requireModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetModified()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetModified()
	 * @method null|\Bitrix\Main\Type\DateTime fillModified()
	 * @method \Bitrix\Main\EO_User getAuthor()
	 * @method \Bitrix\Main\EO_User remindActualAuthor()
	 * @method \Bitrix\Main\EO_User requireAuthor()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment setAuthor(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment resetAuthor()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unsetAuthor()
	 * @method bool hasAuthor()
	 * @method bool isAuthorFilled()
	 * @method bool isAuthorChanged()
	 * @method \Bitrix\Main\EO_User fillAuthor()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment reset($fieldName)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment wakeUp($data)
	 */
	class EO_TemplateFragment extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Fragment\Entity {
	/**
	 * EO_TemplateFragment_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method \int[] getAuthorIdList()
	 * @method \int[] fillAuthorId()
	 * @method \string[] getIsSharedList()
	 * @method \string[] fillIsShared()
	 * @method \int[] getNodeCountList()
	 * @method \int[] fillNodeCount()
	 * @method \string[] getFragmentList()
	 * @method \string[] fillFragment()
	 * @method null|\int[] getUpdatedByList()
	 * @method null|\int[] fillUpdatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreated()
	 * @method null|\Bitrix\Main\Type\DateTime[] getModifiedList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillModified()
	 * @method \Bitrix\Main\EO_User[] getAuthorList()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection getAuthorCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillAuthor()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment $object)
	 * @method bool has(\Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection merge(?\Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection filter(callable $callback)
	 */
	class EO_TemplateFragment_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable */
		static public $dataClass = '\Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable';
	}
}
namespace Bitrix\Bizproc\Workflow\Template\Fragment\Entity {
	/**
	 * @method static EO_TemplateFragment_Query query()
	 * @method static EO_TemplateFragment_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_TemplateFragment_Result getById($id)
	 * @method static EO_TemplateFragment_Result getList(array $parameters = [])
	 * @method static EO_TemplateFragment_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection wakeUpCollection($rows)
	 */
	class TemplateFragmentTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TemplateFragment_Result exec()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection fetchCollection()
	 */
	class EO_TemplateFragment_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment fetchObject()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection fetchCollection()
	 */
	class EO_TemplateFragment_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection createCollection()
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Workflow\Template\Fragment\Entity\EO_TemplateFragment_Collection wakeUpCollection($rows)
	 */
	class EO_TemplateFragment_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable */
namespace Bitrix\Bizproc\Internal\Model\StartStat {
	/**
	 * EO_WorkflowStartStat
	 * @see \Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getTemplateId()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat setTemplateId(\int|\Bitrix\Main\DB\SqlExpression $templateId)
	 * @method bool hasTemplateId()
	 * @method bool isTemplateIdFilled()
	 * @method bool isTemplateIdChanged()
	 * @method \Bitrix\Main\Type\Date getStatDate()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat setStatDate(\Bitrix\Main\Type\Date|\Bitrix\Main\DB\SqlExpression $statDate)
	 * @method bool hasStatDate()
	 * @method bool isStatDateFilled()
	 * @method bool isStatDateChanged()
	 * @method \int getCnt()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat setCnt(\int|\Bitrix\Main\DB\SqlExpression $cnt)
	 * @method bool hasCnt()
	 * @method bool isCntFilled()
	 * @method bool isCntChanged()
	 * @method \int remindActualCnt()
	 * @method \int requireCnt()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat resetCnt()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat unsetCnt()
	 * @method \int fillCnt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat set($fieldName, $value)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat reset($fieldName)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat wakeUp($data)
	 */
	class EO_WorkflowStartStat extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Bizproc\Internal\Model\StartStat {
	/**
	 * EO_WorkflowStartStat_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getTemplateIdList()
	 * @method \Bitrix\Main\Type\Date[] getStatDateList()
	 * @method \int[] getCntList()
	 * @method \int[] fillCnt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat $object)
	 * @method bool has(\Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat getByPrimary($primary)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat[] getAll()
	 * @method bool remove(\Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection merge(?\Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat|null find(callable $callback)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection filter(callable $callback)
	 */
	class EO_WorkflowStartStat_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable */
		static public $dataClass = '\Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable';
	}
}
namespace Bitrix\Bizproc\Internal\Model\StartStat {
	/**
	 * @method static EO_WorkflowStartStat_Query query()
	 * @method static EO_WorkflowStartStat_Result getByPrimary($primary, array $parameters = [])
	 * @method static EO_WorkflowStartStat_Result getById($id)
	 * @method static EO_WorkflowStartStat_Result getList(array $parameters = [])
	 * @method static EO_WorkflowStartStat_Entity getEntity()
	 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat createObject($setDefaultValues = true)
	 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection createCollection()
	 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat wakeUpObject($row)
	 * @method static \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection wakeUpCollection($rows)
	 */
	class WorkflowStartStatTable extends \Bitrix\Main\ORM\Data\DataManager {}
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WorkflowStartStat_Result exec()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection fetchCollection()
	 */
	class EO_WorkflowStartStat_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat fetchObject()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection fetchCollection()
	 */
	class EO_WorkflowStartStat_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat createObject($setDefaultValues = true)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection createCollection()
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat wakeUpObject($row)
	 * @method \Bitrix\Bizproc\Internal\Model\StartStat\EO_WorkflowStartStat_Collection wakeUpCollection($rows)
	 */
	class EO_WorkflowStartStat_Entity extends \Bitrix\Main\ORM\Entity {}
}
