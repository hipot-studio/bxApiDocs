<?php

/* ORMENTITYANNOTATION:Bitrix\Ui\EntityForm\EntityFormConfigAcTable:ui/lib/entityform/entityformconfigactable.php */
namespace Bitrix\Ui\EntityForm {
	/**
	 * EO_EntityFormConfigAc
	 * @see \Bitrix\Ui\EntityForm\EntityFormConfigAcTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getAccessCode()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc setAccessCode(\string|\Bitrix\Main\DB\SqlExpression $accessCode)
	 * @method bool hasAccessCode()
	 * @method bool isAccessCodeFilled()
	 * @method bool isAccessCodeChanged()
	 * @method \string remindActualAccessCode()
	 * @method \string requireAccessCode()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc resetAccessCode()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc unsetAccessCode()
	 * @method \string fillAccessCode()
	 * @method \Bitrix\Main\EO_UserAccess getUserAccess()
	 * @method \Bitrix\Main\EO_UserAccess remindActualUserAccess()
	 * @method \Bitrix\Main\EO_UserAccess requireUserAccess()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc setUserAccess(\Bitrix\Main\EO_UserAccess $object)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc resetUserAccess()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc unsetUserAccess()
	 * @method bool hasUserAccess()
	 * @method bool isUserAccessFilled()
	 * @method bool isUserAccessChanged()
	 * @method \Bitrix\Main\EO_UserAccess fillUserAccess()
	 * @method \int getConfigId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc setConfigId(\int|\Bitrix\Main\DB\SqlExpression $configId)
	 * @method bool hasConfigId()
	 * @method bool isConfigIdFilled()
	 * @method bool isConfigIdChanged()
	 * @method \int remindActualConfigId()
	 * @method \int requireConfigId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc resetConfigId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc unsetConfigId()
	 * @method \int fillConfigId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig getConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig remindActualConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig requireConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc setConfig(\Bitrix\Ui\EntityForm\EO_EntityFormConfig $object)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc resetConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc unsetConfig()
	 * @method bool hasConfig()
	 * @method bool isConfigFilled()
	 * @method bool isConfigChanged()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig fillConfig()
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
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc set($fieldName, $value)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc reset($fieldName)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc wakeUp($data)
	 */
	class EO_EntityFormConfigAc extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Ui\EntityForm\EntityFormConfigAcTable */
		static public $dataClass = '\Bitrix\Ui\EntityForm\EntityFormConfigAcTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Ui\EntityForm {
	/**
	 * EO_EntityFormConfigAc_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getAccessCodeList()
	 * @method \string[] fillAccessCode()
	 * @method \Bitrix\Main\EO_UserAccess[] getUserAccessList()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection getUserAccessCollection()
	 * @method \Bitrix\Main\EO_UserAccess_Collection fillUserAccess()
	 * @method \int[] getConfigIdList()
	 * @method \int[] fillConfigId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig[] getConfigList()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection getConfigCollection()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection fillConfig()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Ui\EntityForm\EO_EntityFormConfigAc $object)
	 * @method bool has(\Bitrix\Ui\EntityForm\EO_EntityFormConfigAc $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc getByPrimary($primary)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc[] getAll()
	 * @method bool remove(\Bitrix\Ui\EntityForm\EO_EntityFormConfigAc $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection merge(?\Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc|null find(callable $callback)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection filter(callable $callback)
	 */
	class EO_EntityFormConfigAc_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Ui\EntityForm\EntityFormConfigAcTable */
		static public $dataClass = '\Bitrix\Ui\EntityForm\EntityFormConfigAcTable';
	}
}
namespace Bitrix\Ui\EntityForm {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_EntityFormConfigAc_Result exec()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc fetchObject()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection fetchCollection()
	 */
	class EO_EntityFormConfigAc_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc fetchObject()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection fetchCollection()
	 */
	class EO_EntityFormConfigAc_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc createObject($setDefaultValues = true)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection createCollection()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc wakeUpObject($row)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfigAc_Collection wakeUpCollection($rows)
	 */
	class EO_EntityFormConfigAc_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Ui\EntityForm\EntityFormConfigTable:ui/lib/entityform/entityformconfigtable.php */
namespace Bitrix\Ui\EntityForm {
	/**
	 * EO_EntityFormConfig
	 * @see \Bitrix\Ui\EntityForm\EntityFormConfigTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getCategory()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setCategory(\string|\Bitrix\Main\DB\SqlExpression $category)
	 * @method bool hasCategory()
	 * @method bool isCategoryFilled()
	 * @method bool isCategoryChanged()
	 * @method \string remindActualCategory()
	 * @method \string requireCategory()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetCategory()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetCategory()
	 * @method \string fillCategory()
	 * @method \string getEntityTypeId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setEntityTypeId(\string|\Bitrix\Main\DB\SqlExpression $entityTypeId)
	 * @method bool hasEntityTypeId()
	 * @method bool isEntityTypeIdFilled()
	 * @method bool isEntityTypeIdChanged()
	 * @method \string remindActualEntityTypeId()
	 * @method \string requireEntityTypeId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetEntityTypeId()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetEntityTypeId()
	 * @method \string fillEntityTypeId()
	 * @method \string getName()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setName(\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method \string remindActualName()
	 * @method \string requireName()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetName()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetName()
	 * @method \string fillName()
	 * @method array getConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setConfig(array|\Bitrix\Main\DB\SqlExpression $config)
	 * @method bool hasConfig()
	 * @method bool isConfigFilled()
	 * @method bool isConfigChanged()
	 * @method array remindActualConfig()
	 * @method array requireConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetConfig()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetConfig()
	 * @method array fillConfig()
	 * @method \boolean getCommon()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setCommon(\boolean|\Bitrix\Main\DB\SqlExpression $common)
	 * @method bool hasCommon()
	 * @method bool isCommonFilled()
	 * @method bool isCommonChanged()
	 * @method \boolean remindActualCommon()
	 * @method \boolean requireCommon()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetCommon()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetCommon()
	 * @method \boolean fillCommon()
	 * @method \boolean getAutoApplyScope()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setAutoApplyScope(\boolean|\Bitrix\Main\DB\SqlExpression $autoApplyScope)
	 * @method bool hasAutoApplyScope()
	 * @method bool isAutoApplyScopeFilled()
	 * @method bool isAutoApplyScopeChanged()
	 * @method \boolean remindActualAutoApplyScope()
	 * @method \boolean requireAutoApplyScope()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetAutoApplyScope()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetAutoApplyScope()
	 * @method \boolean fillAutoApplyScope()
	 * @method \string getOptionCategory()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setOptionCategory(\string|\Bitrix\Main\DB\SqlExpression $optionCategory)
	 * @method bool hasOptionCategory()
	 * @method bool isOptionCategoryFilled()
	 * @method bool isOptionCategoryChanged()
	 * @method \string remindActualOptionCategory()
	 * @method \string requireOptionCategory()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetOptionCategory()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetOptionCategory()
	 * @method \string fillOptionCategory()
	 * @method \boolean getOnAdd()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setOnAdd(\boolean|\Bitrix\Main\DB\SqlExpression $onAdd)
	 * @method bool hasOnAdd()
	 * @method bool isOnAddFilled()
	 * @method bool isOnAddChanged()
	 * @method \boolean remindActualOnAdd()
	 * @method \boolean requireOnAdd()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetOnAdd()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetOnAdd()
	 * @method \boolean fillOnAdd()
	 * @method \boolean getOnUpdate()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig setOnUpdate(\boolean|\Bitrix\Main\DB\SqlExpression $onUpdate)
	 * @method bool hasOnUpdate()
	 * @method bool isOnUpdateFilled()
	 * @method bool isOnUpdateChanged()
	 * @method \boolean remindActualOnUpdate()
	 * @method \boolean requireOnUpdate()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig resetOnUpdate()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unsetOnUpdate()
	 * @method \boolean fillOnUpdate()
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
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig set($fieldName, $value)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig reset($fieldName)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Ui\EntityForm\EO_EntityFormConfig wakeUp($data)
	 */
	class EO_EntityFormConfig extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Ui\EntityForm\EntityFormConfigTable */
		static public $dataClass = '\Bitrix\Ui\EntityForm\EntityFormConfigTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Ui\EntityForm {
	/**
	 * EO_EntityFormConfig_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getCategoryList()
	 * @method \string[] fillCategory()
	 * @method \string[] getEntityTypeIdList()
	 * @method \string[] fillEntityTypeId()
	 * @method \string[] getNameList()
	 * @method \string[] fillName()
	 * @method array[] getConfigList()
	 * @method array[] fillConfig()
	 * @method \boolean[] getCommonList()
	 * @method \boolean[] fillCommon()
	 * @method \boolean[] getAutoApplyScopeList()
	 * @method \boolean[] fillAutoApplyScope()
	 * @method \string[] getOptionCategoryList()
	 * @method \string[] fillOptionCategory()
	 * @method \boolean[] getOnAddList()
	 * @method \boolean[] fillOnAdd()
	 * @method \boolean[] getOnUpdateList()
	 * @method \boolean[] fillOnUpdate()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Ui\EntityForm\EO_EntityFormConfig $object)
	 * @method bool has(\Bitrix\Ui\EntityForm\EO_EntityFormConfig $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig getByPrimary($primary)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig[] getAll()
	 * @method bool remove(\Bitrix\Ui\EntityForm\EO_EntityFormConfig $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection merge(?\Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig|null find(callable $callback)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection filter(callable $callback)
	 */
	class EO_EntityFormConfig_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Ui\EntityForm\EntityFormConfigTable */
		static public $dataClass = '\Bitrix\Ui\EntityForm\EntityFormConfigTable';
	}
}
namespace Bitrix\Ui\EntityForm {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_EntityFormConfig_Result exec()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig fetchObject()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection fetchCollection()
	 */
	class EO_EntityFormConfig_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig fetchObject()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection fetchCollection()
	 */
	class EO_EntityFormConfig_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig createObject($setDefaultValues = true)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection createCollection()
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig wakeUpObject($row)
	 * @method \Bitrix\Ui\EntityForm\EO_EntityFormConfig_Collection wakeUpCollection($rows)
	 */
	class EO_EntityFormConfig_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\UI\Avatar\Model\ItemTable:ui/lib/avatar/model/itemtable.php */
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_Item
	 * @see \Bitrix\UI\Avatar\Model\ItemTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setFileId(\int|\Bitrix\Main\DB\SqlExpression $fileId)
	 * @method bool hasFileId()
	 * @method bool isFileIdFilled()
	 * @method bool isFileIdChanged()
	 * @method \int remindActualFileId()
	 * @method \int requireFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetFileId()
	 * @method \int fillFileId()
	 * @method \string getOwnerType()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setOwnerType(\string|\Bitrix\Main\DB\SqlExpression $ownerType)
	 * @method bool hasOwnerType()
	 * @method bool isOwnerTypeFilled()
	 * @method bool isOwnerTypeChanged()
	 * @method \string remindActualOwnerType()
	 * @method \string requireOwnerType()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetOwnerType()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetOwnerType()
	 * @method \string fillOwnerType()
	 * @method \string getOwnerId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setOwnerId(\string|\Bitrix\Main\DB\SqlExpression $ownerId)
	 * @method bool hasOwnerId()
	 * @method bool isOwnerIdFilled()
	 * @method bool isOwnerIdChanged()
	 * @method \string remindActualOwnerId()
	 * @method \string requireOwnerId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetOwnerId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetOwnerId()
	 * @method \string fillOwnerId()
	 * @method \string getGroupId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setGroupId(\string|\Bitrix\Main\DB\SqlExpression $groupId)
	 * @method bool hasGroupId()
	 * @method bool isGroupIdFilled()
	 * @method bool isGroupIdChanged()
	 * @method \string remindActualGroupId()
	 * @method \string requireGroupId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetGroupId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetGroupId()
	 * @method \string fillGroupId()
	 * @method \string getTitle()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setTitle(\string|\Bitrix\Main\DB\SqlExpression $title)
	 * @method bool hasTitle()
	 * @method bool isTitleFilled()
	 * @method bool isTitleChanged()
	 * @method \string remindActualTitle()
	 * @method \string requireTitle()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetTitle()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetTitle()
	 * @method \string fillTitle()
	 * @method \string getDescription()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetDescription()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetDescription()
	 * @method \string fillDescription()
	 * @method \int getSort()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetSort()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetSort()
	 * @method \int fillSort()
	 * @method \Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setTimestampX(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method \Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetTimestampX()
	 * @method \Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method \Bitrix\Main\EO_File getFile()
	 * @method \Bitrix\Main\EO_File remindActualFile()
	 * @method \Bitrix\Main\EO_File requireFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setFile(\Bitrix\Main\EO_File $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetFile()
	 * @method bool hasFile()
	 * @method bool isFileFilled()
	 * @method bool isFileChanged()
	 * @method \Bitrix\Main\EO_File fillFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access getSharedFor()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access remindActualSharedFor()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access requireSharedFor()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setSharedFor(\Bitrix\UI\Avatar\Model\EO_Access $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetSharedFor()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetSharedFor()
	 * @method bool hasSharedFor()
	 * @method bool isSharedForFilled()
	 * @method bool isSharedForChanged()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access fillSharedFor()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed getRecentlyUsedBy()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed remindActualRecentlyUsedBy()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed requireRecentlyUsedBy()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item setRecentlyUsedBy(\Bitrix\UI\Avatar\Model\EO_RecentlyUsed $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item resetRecentlyUsedBy()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unsetRecentlyUsedBy()
	 * @method bool hasRecentlyUsedBy()
	 * @method bool isRecentlyUsedByFilled()
	 * @method bool isRecentlyUsedByChanged()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed fillRecentlyUsedBy()
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
	 * @method \Bitrix\UI\Avatar\Model\EO_Item set($fieldName, $value)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item reset($fieldName)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\UI\Avatar\Model\EO_Item wakeUp($data)
	 */
	class EO_Item extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\UI\Avatar\Model\ItemTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\ItemTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_Item_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getFileIdList()
	 * @method \int[] fillFileId()
	 * @method \string[] getOwnerTypeList()
	 * @method \string[] fillOwnerType()
	 * @method \string[] getOwnerIdList()
	 * @method \string[] fillOwnerId()
	 * @method \string[] getGroupIdList()
	 * @method \string[] fillGroupId()
	 * @method \string[] getTitleList()
	 * @method \string[] fillTitle()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method \Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method \Bitrix\Main\EO_File[] getFileList()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection getFileCollection()
	 * @method \Bitrix\Main\EO_File_Collection fillFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access[] getSharedForList()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection getSharedForCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection fillSharedFor()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed[] getRecentlyUsedByList()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection getRecentlyUsedByCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection fillRecentlyUsedBy()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\UI\Avatar\Model\EO_Item $object)
	 * @method bool has(\Bitrix\UI\Avatar\Model\EO_Item $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item getByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item[] getAll()
	 * @method bool remove(\Bitrix\UI\Avatar\Model\EO_Item $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\UI\Avatar\Model\EO_Item_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\UI\Avatar\Model\EO_Item current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection merge(?\Bitrix\UI\Avatar\Model\EO_Item_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item|null find(callable $callback)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection filter(callable $callback)
	 */
	class EO_Item_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\UI\Avatar\Model\ItemTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\ItemTable';
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Item_Result exec()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection fetchCollection()
	 */
	class EO_Item_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_Item fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection fetchCollection()
	 */
	class EO_Item_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_Item createObject($setDefaultValues = true)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection createCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item wakeUpObject($row)
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection wakeUpCollection($rows)
	 */
	class EO_Item_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\UI\Avatar\Model\GroupTable:ui/lib/avatar/model/grouptable.php */
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_Group
	 * @see \Bitrix\UI\Avatar\Model\GroupTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setTimestampX(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method \Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group resetTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unsetTimestampX()
	 * @method \Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method \string getOwnerType()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setOwnerType(\string|\Bitrix\Main\DB\SqlExpression $ownerType)
	 * @method bool hasOwnerType()
	 * @method bool isOwnerTypeFilled()
	 * @method bool isOwnerTypeChanged()
	 * @method \string remindActualOwnerType()
	 * @method \string requireOwnerType()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group resetOwnerType()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unsetOwnerType()
	 * @method \string fillOwnerType()
	 * @method \string getOwnerId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setOwnerId(\string|\Bitrix\Main\DB\SqlExpression $ownerId)
	 * @method bool hasOwnerId()
	 * @method bool isOwnerIdFilled()
	 * @method bool isOwnerIdChanged()
	 * @method \string remindActualOwnerId()
	 * @method \string requireOwnerId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group resetOwnerId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unsetOwnerId()
	 * @method \string fillOwnerId()
	 * @method \int getSort()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group resetSort()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unsetSort()
	 * @method \int fillSort()
	 * @method \string getTitle()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setTitle(\string|\Bitrix\Main\DB\SqlExpression $title)
	 * @method bool hasTitle()
	 * @method bool isTitleFilled()
	 * @method bool isTitleChanged()
	 * @method \string remindActualTitle()
	 * @method \string requireTitle()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group resetTitle()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unsetTitle()
	 * @method \string fillTitle()
	 * @method \string getDescription()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group setDescription(\string|\Bitrix\Main\DB\SqlExpression $description)
	 * @method bool hasDescription()
	 * @method bool isDescriptionFilled()
	 * @method bool isDescriptionChanged()
	 * @method \string remindActualDescription()
	 * @method \string requireDescription()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group resetDescription()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unsetDescription()
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
	 * @method \Bitrix\UI\Avatar\Model\EO_Group set($fieldName, $value)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group reset($fieldName)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\UI\Avatar\Model\EO_Group wakeUp($data)
	 */
	class EO_Group extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\UI\Avatar\Model\GroupTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\GroupTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_Group_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method \Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method \string[] getOwnerTypeList()
	 * @method \string[] fillOwnerType()
	 * @method \string[] getOwnerIdList()
	 * @method \string[] fillOwnerId()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \string[] getTitleList()
	 * @method \string[] fillTitle()
	 * @method \string[] getDescriptionList()
	 * @method \string[] fillDescription()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\UI\Avatar\Model\EO_Group $object)
	 * @method bool has(\Bitrix\UI\Avatar\Model\EO_Group $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group getByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group[] getAll()
	 * @method bool remove(\Bitrix\UI\Avatar\Model\EO_Group $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\UI\Avatar\Model\EO_Group_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\UI\Avatar\Model\EO_Group current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\UI\Avatar\Model\EO_Group_Collection merge(?\Bitrix\UI\Avatar\Model\EO_Group_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group|null find(callable $callback)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group_Collection filter(callable $callback)
	 */
	class EO_Group_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\UI\Avatar\Model\GroupTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\GroupTable';
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Group_Result exec()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group_Collection fetchCollection()
	 */
	class EO_Group_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_Group fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group_Collection fetchCollection()
	 */
	class EO_Group_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_Group createObject($setDefaultValues = true)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group_Collection createCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_Group wakeUpObject($row)
	 * @method \Bitrix\UI\Avatar\Model\EO_Group_Collection wakeUpCollection($rows)
	 */
	class EO_Group_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\UI\Avatar\Model\AccessTable:ui/lib/avatar/model/accesstable.php */
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_Access
	 * @see \Bitrix\UI\Avatar\Model\AccessTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access setItemId(\int|\Bitrix\Main\DB\SqlExpression $itemId)
	 * @method bool hasItemId()
	 * @method bool isItemIdFilled()
	 * @method bool isItemIdChanged()
	 * @method \int remindActualItemId()
	 * @method \int requireItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access resetItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access unsetItemId()
	 * @method \int fillItemId()
	 * @method \string getAccessCode()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access setAccessCode(\string|\Bitrix\Main\DB\SqlExpression $accessCode)
	 * @method bool hasAccessCode()
	 * @method bool isAccessCodeFilled()
	 * @method bool isAccessCodeChanged()
	 * @method \string remindActualAccessCode()
	 * @method \string requireAccessCode()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access resetAccessCode()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access unsetAccessCode()
	 * @method \string fillAccessCode()
	 * @method \Bitrix\Main\EO_UserAccess getUserAccess()
	 * @method \Bitrix\Main\EO_UserAccess remindActualUserAccess()
	 * @method \Bitrix\Main\EO_UserAccess requireUserAccess()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access setUserAccess(\Bitrix\Main\EO_UserAccess $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access resetUserAccess()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access unsetUserAccess()
	 * @method bool hasUserAccess()
	 * @method bool isUserAccessFilled()
	 * @method bool isUserAccessChanged()
	 * @method \Bitrix\Main\EO_UserAccess fillUserAccess()
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
	 * @method \Bitrix\UI\Avatar\Model\EO_Access set($fieldName, $value)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access reset($fieldName)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\UI\Avatar\Model\EO_Access wakeUp($data)
	 */
	class EO_Access extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\UI\Avatar\Model\AccessTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\AccessTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_Access_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getItemIdList()
	 * @method \int[] fillItemId()
	 * @method \string[] getAccessCodeList()
	 * @method \string[] fillAccessCode()
	 * @method \Bitrix\Main\EO_UserAccess[] getUserAccessList()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection getUserAccessCollection()
	 * @method \Bitrix\Main\EO_UserAccess_Collection fillUserAccess()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\UI\Avatar\Model\EO_Access $object)
	 * @method bool has(\Bitrix\UI\Avatar\Model\EO_Access $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access getByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access[] getAll()
	 * @method bool remove(\Bitrix\UI\Avatar\Model\EO_Access $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\UI\Avatar\Model\EO_Access_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\UI\Avatar\Model\EO_Access current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection merge(?\Bitrix\UI\Avatar\Model\EO_Access_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access|null find(callable $callback)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection filter(callable $callback)
	 */
	class EO_Access_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\UI\Avatar\Model\AccessTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\AccessTable';
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Access_Result exec()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection fetchCollection()
	 */
	class EO_Access_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_Access fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection fetchCollection()
	 */
	class EO_Access_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_Access createObject($setDefaultValues = true)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection createCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_Access wakeUpObject($row)
	 * @method \Bitrix\UI\Avatar\Model\EO_Access_Collection wakeUpCollection($rows)
	 */
	class EO_Access_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\UI\Avatar\Model\RecentlyUsedTable:ui/lib/avatar/model/recentlyusedtable.php */
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_RecentlyUsed
	 * @see \Bitrix\UI\Avatar\Model\RecentlyUsedTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed setItemId(\int|\Bitrix\Main\DB\SqlExpression $itemId)
	 * @method bool hasItemId()
	 * @method bool isItemIdFilled()
	 * @method bool isItemIdChanged()
	 * @method \int remindActualItemId()
	 * @method \int requireItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed resetItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed unsetItemId()
	 * @method \int fillItemId()
	 * @method \int getUserId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed resetUserId()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed setTimestampX(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method \Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed resetTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed unsetTimestampX()
	 * @method \Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item getMask()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item remindActualMask()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item requireMask()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed setMask(\Bitrix\UI\Avatar\Model\EO_Item $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed resetMask()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed unsetMask()
	 * @method bool hasMask()
	 * @method bool isMaskFilled()
	 * @method bool isMaskChanged()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item fillMask()
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
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed set($fieldName, $value)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed reset($fieldName)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\UI\Avatar\Model\EO_RecentlyUsed wakeUp($data)
	 */
	class EO_RecentlyUsed extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\UI\Avatar\Model\RecentlyUsedTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\RecentlyUsedTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_RecentlyUsed_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getItemIdList()
	 * @method \int[] fillItemId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method \Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item[] getMaskList()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection getMaskCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection fillMask()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\UI\Avatar\Model\EO_RecentlyUsed $object)
	 * @method bool has(\Bitrix\UI\Avatar\Model\EO_RecentlyUsed $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed getByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed[] getAll()
	 * @method bool remove(\Bitrix\UI\Avatar\Model\EO_RecentlyUsed $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection merge(?\Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed|null find(callable $callback)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection filter(callable $callback)
	 */
	class EO_RecentlyUsed_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\UI\Avatar\Model\RecentlyUsedTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\RecentlyUsedTable';
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RecentlyUsed_Result exec()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection fetchCollection()
	 */
	class EO_RecentlyUsed_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection fetchCollection()
	 */
	class EO_RecentlyUsed_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed createObject($setDefaultValues = true)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection createCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed wakeUpObject($row)
	 * @method \Bitrix\UI\Avatar\Model\EO_RecentlyUsed_Collection wakeUpCollection($rows)
	 */
	class EO_RecentlyUsed_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\UI\Avatar\Model\ItemToFileTable:ui/lib/avatar/model/itemtofiletable.php */
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_ItemToFile
	 * @see \Bitrix\UI\Avatar\Model\ItemToFileTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getOriginalFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setOriginalFileId(\int|\Bitrix\Main\DB\SqlExpression $originalFileId)
	 * @method bool hasOriginalFileId()
	 * @method bool isOriginalFileIdFilled()
	 * @method bool isOriginalFileIdChanged()
	 * @method \int remindActualOriginalFileId()
	 * @method \int requireOriginalFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetOriginalFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetOriginalFileId()
	 * @method \int fillOriginalFileId()
	 * @method \int getFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setFileId(\int|\Bitrix\Main\DB\SqlExpression $fileId)
	 * @method bool hasFileId()
	 * @method bool isFileIdFilled()
	 * @method bool isFileIdChanged()
	 * @method \int remindActualFileId()
	 * @method \int requireFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetFileId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetFileId()
	 * @method \int fillFileId()
	 * @method \int getItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setItemId(\int|\Bitrix\Main\DB\SqlExpression $itemId)
	 * @method bool hasItemId()
	 * @method bool isItemIdFilled()
	 * @method bool isItemIdChanged()
	 * @method \int remindActualItemId()
	 * @method \int requireItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetItemId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetItemId()
	 * @method \int fillItemId()
	 * @method \int getUserId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetUserId()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setTimestampX(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method \Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetTimestampX()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetTimestampX()
	 * @method \Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method \Bitrix\Main\EO_File getFile()
	 * @method \Bitrix\Main\EO_File remindActualFile()
	 * @method \Bitrix\Main\EO_File requireFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setFile(\Bitrix\Main\EO_File $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetFile()
	 * @method bool hasFile()
	 * @method bool isFileFilled()
	 * @method bool isFileChanged()
	 * @method \Bitrix\Main\EO_File fillFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item getItem()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item remindActualItem()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item requireItem()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setItem(\Bitrix\UI\Avatar\Model\EO_Item $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetItem()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetItem()
	 * @method bool hasItem()
	 * @method bool isItemFilled()
	 * @method bool isItemChanged()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item fillItem()
	 * @method \Bitrix\Main\EO_User getUser()
	 * @method \Bitrix\Main\EO_User remindActualUser()
	 * @method \Bitrix\Main\EO_User requireUser()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile setUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile resetUser()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unsetUser()
	 * @method bool hasUser()
	 * @method bool isUserFilled()
	 * @method bool isUserChanged()
	 * @method \Bitrix\Main\EO_User fillUser()
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
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile set($fieldName, $value)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile reset($fieldName)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\UI\Avatar\Model\EO_ItemToFile wakeUp($data)
	 */
	class EO_ItemToFile extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\UI\Avatar\Model\ItemToFileTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\ItemToFileTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * EO_ItemToFile_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getOriginalFileIdList()
	 * @method \int[] fillOriginalFileId()
	 * @method \int[] getFileIdList()
	 * @method \int[] fillFileId()
	 * @method \int[] getItemIdList()
	 * @method \int[] fillItemId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method \Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method \Bitrix\Main\EO_File[] getFileList()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection getFileCollection()
	 * @method \Bitrix\Main\EO_File_Collection fillFile()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item[] getItemList()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection getItemCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_Item_Collection fillItem()
	 * @method \Bitrix\Main\EO_User[] getUserList()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection getUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\UI\Avatar\Model\EO_ItemToFile $object)
	 * @method bool has(\Bitrix\UI\Avatar\Model\EO_ItemToFile $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile getByPrimary($primary)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile[] getAll()
	 * @method bool remove(\Bitrix\UI\Avatar\Model\EO_ItemToFile $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection merge(?\Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile|null find(callable $callback)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection filter(callable $callback)
	 */
	class EO_ItemToFile_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\UI\Avatar\Model\ItemToFileTable */
		static public $dataClass = '\Bitrix\UI\Avatar\Model\ItemToFileTable';
	}
}
namespace Bitrix\UI\Avatar\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ItemToFile_Result exec()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection fetchCollection()
	 */
	class EO_ItemToFile_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile fetchObject()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection fetchCollection()
	 */
	class EO_ItemToFile_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile createObject($setDefaultValues = true)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection createCollection()
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile wakeUpObject($row)
	 * @method \Bitrix\UI\Avatar\Model\EO_ItemToFile_Collection wakeUpCollection($rows)
	 */
	class EO_ItemToFile_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\UI\FileUploader\TempFileTable:ui/lib/fileuploader/tempfiletable.php */
namespace Bitrix\UI\FileUploader {
	/**
	 * TempFile
	 * @see \Bitrix\UI\FileUploader\TempFileTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\UI\FileUploader\TempFile setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getGuid()
	 * @method \Bitrix\UI\FileUploader\TempFile setGuid(\string|\Bitrix\Main\DB\SqlExpression $guid)
	 * @method bool hasGuid()
	 * @method bool isGuidFilled()
	 * @method bool isGuidChanged()
	 * @method \string remindActualGuid()
	 * @method \string requireGuid()
	 * @method \Bitrix\UI\FileUploader\TempFile resetGuid()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetGuid()
	 * @method \string fillGuid()
	 * @method \int getFileId()
	 * @method \Bitrix\UI\FileUploader\TempFile setFileId(\int|\Bitrix\Main\DB\SqlExpression $fileId)
	 * @method bool hasFileId()
	 * @method bool isFileIdFilled()
	 * @method bool isFileIdChanged()
	 * @method \int remindActualFileId()
	 * @method \int requireFileId()
	 * @method \Bitrix\UI\FileUploader\TempFile resetFileId()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetFileId()
	 * @method \int fillFileId()
	 * @method \string getFilename()
	 * @method \Bitrix\UI\FileUploader\TempFile setFilename(\string|\Bitrix\Main\DB\SqlExpression $filename)
	 * @method bool hasFilename()
	 * @method bool isFilenameFilled()
	 * @method bool isFilenameChanged()
	 * @method \string remindActualFilename()
	 * @method \string requireFilename()
	 * @method \Bitrix\UI\FileUploader\TempFile resetFilename()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetFilename()
	 * @method \string fillFilename()
	 * @method \int getSize()
	 * @method \Bitrix\UI\FileUploader\TempFile setSize(\int|\Bitrix\Main\DB\SqlExpression $size)
	 * @method bool hasSize()
	 * @method bool isSizeFilled()
	 * @method bool isSizeChanged()
	 * @method \int remindActualSize()
	 * @method \int requireSize()
	 * @method \Bitrix\UI\FileUploader\TempFile resetSize()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetSize()
	 * @method \int fillSize()
	 * @method \string getPath()
	 * @method \Bitrix\UI\FileUploader\TempFile setPath(\string|\Bitrix\Main\DB\SqlExpression $path)
	 * @method bool hasPath()
	 * @method bool isPathFilled()
	 * @method bool isPathChanged()
	 * @method \string remindActualPath()
	 * @method \string requirePath()
	 * @method \Bitrix\UI\FileUploader\TempFile resetPath()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetPath()
	 * @method \string fillPath()
	 * @method \string getMimetype()
	 * @method \Bitrix\UI\FileUploader\TempFile setMimetype(\string|\Bitrix\Main\DB\SqlExpression $mimetype)
	 * @method bool hasMimetype()
	 * @method bool isMimetypeFilled()
	 * @method bool isMimetypeChanged()
	 * @method \string remindActualMimetype()
	 * @method \string requireMimetype()
	 * @method \Bitrix\UI\FileUploader\TempFile resetMimetype()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetMimetype()
	 * @method \string fillMimetype()
	 * @method \int getReceivedSize()
	 * @method \Bitrix\UI\FileUploader\TempFile setReceivedSize(\int|\Bitrix\Main\DB\SqlExpression $receivedSize)
	 * @method bool hasReceivedSize()
	 * @method bool isReceivedSizeFilled()
	 * @method bool isReceivedSizeChanged()
	 * @method \int remindActualReceivedSize()
	 * @method \int requireReceivedSize()
	 * @method \Bitrix\UI\FileUploader\TempFile resetReceivedSize()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetReceivedSize()
	 * @method \int fillReceivedSize()
	 * @method \int getWidth()
	 * @method \Bitrix\UI\FileUploader\TempFile setWidth(\int|\Bitrix\Main\DB\SqlExpression $width)
	 * @method bool hasWidth()
	 * @method bool isWidthFilled()
	 * @method bool isWidthChanged()
	 * @method \int remindActualWidth()
	 * @method \int requireWidth()
	 * @method \Bitrix\UI\FileUploader\TempFile resetWidth()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetWidth()
	 * @method \int fillWidth()
	 * @method \int getHeight()
	 * @method \Bitrix\UI\FileUploader\TempFile setHeight(\int|\Bitrix\Main\DB\SqlExpression $height)
	 * @method bool hasHeight()
	 * @method bool isHeightFilled()
	 * @method bool isHeightChanged()
	 * @method \int remindActualHeight()
	 * @method \int requireHeight()
	 * @method \Bitrix\UI\FileUploader\TempFile resetHeight()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetHeight()
	 * @method \int fillHeight()
	 * @method \int getBucketId()
	 * @method \Bitrix\UI\FileUploader\TempFile setBucketId(\int|\Bitrix\Main\DB\SqlExpression $bucketId)
	 * @method bool hasBucketId()
	 * @method bool isBucketIdFilled()
	 * @method bool isBucketIdChanged()
	 * @method \int remindActualBucketId()
	 * @method \int requireBucketId()
	 * @method \Bitrix\UI\FileUploader\TempFile resetBucketId()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetBucketId()
	 * @method \int fillBucketId()
	 * @method \string getModuleId()
	 * @method \Bitrix\UI\FileUploader\TempFile setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\UI\FileUploader\TempFile resetModuleId()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getController()
	 * @method \Bitrix\UI\FileUploader\TempFile setController(\string|\Bitrix\Main\DB\SqlExpression $controller)
	 * @method bool hasController()
	 * @method bool isControllerFilled()
	 * @method bool isControllerChanged()
	 * @method \string remindActualController()
	 * @method \string requireController()
	 * @method \Bitrix\UI\FileUploader\TempFile resetController()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetController()
	 * @method \string fillController()
	 * @method array getControllerOptions()
	 * @method \Bitrix\UI\FileUploader\TempFile setControllerOptions(array|\Bitrix\Main\DB\SqlExpression $controllerOptions)
	 * @method bool hasControllerOptions()
	 * @method bool isControllerOptionsFilled()
	 * @method bool isControllerOptionsChanged()
	 * @method array remindActualControllerOptions()
	 * @method array requireControllerOptions()
	 * @method \Bitrix\UI\FileUploader\TempFile resetControllerOptions()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetControllerOptions()
	 * @method array fillControllerOptions()
	 * @method \boolean getCloud()
	 * @method \Bitrix\UI\FileUploader\TempFile setCloud(\boolean|\Bitrix\Main\DB\SqlExpression $cloud)
	 * @method bool hasCloud()
	 * @method bool isCloudFilled()
	 * @method bool isCloudChanged()
	 * @method \boolean remindActualCloud()
	 * @method \boolean requireCloud()
	 * @method \Bitrix\UI\FileUploader\TempFile resetCloud()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetCloud()
	 * @method \boolean fillCloud()
	 * @method \boolean getUploaded()
	 * @method \Bitrix\UI\FileUploader\TempFile setUploaded(\boolean|\Bitrix\Main\DB\SqlExpression $uploaded)
	 * @method bool hasUploaded()
	 * @method bool isUploadedFilled()
	 * @method bool isUploadedChanged()
	 * @method \boolean remindActualUploaded()
	 * @method \boolean requireUploaded()
	 * @method \Bitrix\UI\FileUploader\TempFile resetUploaded()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetUploaded()
	 * @method \boolean fillUploaded()
	 * @method \boolean getDeleted()
	 * @method \Bitrix\UI\FileUploader\TempFile setDeleted(\boolean|\Bitrix\Main\DB\SqlExpression $deleted)
	 * @method bool hasDeleted()
	 * @method bool isDeletedFilled()
	 * @method bool isDeletedChanged()
	 * @method \boolean remindActualDeleted()
	 * @method \boolean requireDeleted()
	 * @method \Bitrix\UI\FileUploader\TempFile resetDeleted()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetDeleted()
	 * @method \boolean fillDeleted()
	 * @method \int getCreatedBy()
	 * @method \Bitrix\UI\FileUploader\TempFile setCreatedBy(\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method \int remindActualCreatedBy()
	 * @method \int requireCreatedBy()
	 * @method \Bitrix\UI\FileUploader\TempFile resetCreatedBy()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetCreatedBy()
	 * @method \int fillCreatedBy()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\UI\FileUploader\TempFile setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\UI\FileUploader\TempFile resetCreatedAt()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\EO_File getFile()
	 * @method \Bitrix\Main\EO_File remindActualFile()
	 * @method \Bitrix\Main\EO_File requireFile()
	 * @method \Bitrix\UI\FileUploader\TempFile setFile(\Bitrix\Main\EO_File $object)
	 * @method \Bitrix\UI\FileUploader\TempFile resetFile()
	 * @method \Bitrix\UI\FileUploader\TempFile unsetFile()
	 * @method bool hasFile()
	 * @method bool isFileFilled()
	 * @method bool isFileChanged()
	 * @method \Bitrix\Main\EO_File fillFile()
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
	 * @method \Bitrix\UI\FileUploader\TempFile set($fieldName, $value)
	 * @method \Bitrix\UI\FileUploader\TempFile reset($fieldName)
	 * @method \Bitrix\UI\FileUploader\TempFile unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\UI\FileUploader\TempFile wakeUp($data)
	 */
	class EO_TempFile extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\UI\FileUploader\TempFileTable */
		static public $dataClass = '\Bitrix\UI\FileUploader\TempFileTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\UI\FileUploader {
	/**
	 * EO_TempFile_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getGuidList()
	 * @method \string[] fillGuid()
	 * @method \int[] getFileIdList()
	 * @method \int[] fillFileId()
	 * @method \string[] getFilenameList()
	 * @method \string[] fillFilename()
	 * @method \int[] getSizeList()
	 * @method \int[] fillSize()
	 * @method \string[] getPathList()
	 * @method \string[] fillPath()
	 * @method \string[] getMimetypeList()
	 * @method \string[] fillMimetype()
	 * @method \int[] getReceivedSizeList()
	 * @method \int[] fillReceivedSize()
	 * @method \int[] getWidthList()
	 * @method \int[] fillWidth()
	 * @method \int[] getHeightList()
	 * @method \int[] fillHeight()
	 * @method \int[] getBucketIdList()
	 * @method \int[] fillBucketId()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getControllerList()
	 * @method \string[] fillController()
	 * @method array[] getControllerOptionsList()
	 * @method array[] fillControllerOptions()
	 * @method \boolean[] getCloudList()
	 * @method \boolean[] fillCloud()
	 * @method \boolean[] getUploadedList()
	 * @method \boolean[] fillUploaded()
	 * @method \boolean[] getDeletedList()
	 * @method \boolean[] fillDeleted()
	 * @method \int[] getCreatedByList()
	 * @method \int[] fillCreatedBy()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\EO_File[] getFileList()
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection getFileCollection()
	 * @method \Bitrix\Main\EO_File_Collection fillFile()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\UI\FileUploader\TempFile $object)
	 * @method bool has(\Bitrix\UI\FileUploader\TempFile $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\UI\FileUploader\TempFile getByPrimary($primary)
	 * @method \Bitrix\UI\FileUploader\TempFile[] getAll()
	 * @method bool remove(\Bitrix\UI\FileUploader\TempFile $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\UI\FileUploader\EO_TempFile_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\UI\FileUploader\TempFile current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection merge(?\Bitrix\UI\FileUploader\EO_TempFile_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\UI\FileUploader\TempFile|null find(callable $callback)
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection filter(callable $callback)
	 */
	class EO_TempFile_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\UI\FileUploader\TempFileTable */
		static public $dataClass = '\Bitrix\UI\FileUploader\TempFileTable';
	}
}
namespace Bitrix\UI\FileUploader {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_TempFile_Result exec()
	 * @method \Bitrix\UI\FileUploader\TempFile fetchObject()
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection fetchCollection()
	 */
	class EO_TempFile_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\UI\FileUploader\TempFile fetchObject()
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection fetchCollection()
	 */
	class EO_TempFile_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\UI\FileUploader\TempFile createObject($setDefaultValues = true)
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection createCollection()
	 * @method \Bitrix\UI\FileUploader\TempFile wakeUpObject($row)
	 * @method \Bitrix\UI\FileUploader\EO_TempFile_Collection wakeUpCollection($rows)
	 */
	class EO_TempFile_Entity extends \Bitrix\Main\ORM\Entity {}
}