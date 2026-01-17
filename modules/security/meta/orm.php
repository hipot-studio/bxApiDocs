<?php

/* ORMENTITYANNOTATION:Bitrix\Security\FilterMaskTable:security/lib/filtermasktable.php */
namespace Bitrix\Security {
	/**
	 * FilterMask
	 * @see \Bitrix\Security\FilterMaskTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\FilterMask setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getSort()
	 * @method \Bitrix\Security\FilterMask setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\FilterMask resetSort()
	 * @method \Bitrix\Security\FilterMask unsetSort()
	 * @method \int fillSort()
	 * @method null|\string getSiteId()
	 * @method \Bitrix\Security\FilterMask setSiteId(null|\string|\Bitrix\Main\DB\SqlExpression $siteId)
	 * @method bool hasSiteId()
	 * @method bool isSiteIdFilled()
	 * @method bool isSiteIdChanged()
	 * @method null|\string remindActualSiteId()
	 * @method null|\string requireSiteId()
	 * @method \Bitrix\Security\FilterMask resetSiteId()
	 * @method \Bitrix\Security\FilterMask unsetSiteId()
	 * @method null|\string fillSiteId()
	 * @method null|\string getFilterMask()
	 * @method \Bitrix\Security\FilterMask setFilterMask(null|\string|\Bitrix\Main\DB\SqlExpression $filterMask)
	 * @method bool hasFilterMask()
	 * @method bool isFilterMaskFilled()
	 * @method bool isFilterMaskChanged()
	 * @method null|\string remindActualFilterMask()
	 * @method null|\string requireFilterMask()
	 * @method \Bitrix\Security\FilterMask resetFilterMask()
	 * @method \Bitrix\Security\FilterMask unsetFilterMask()
	 * @method null|\string fillFilterMask()
	 * @method null|\string getLikeMask()
	 * @method \Bitrix\Security\FilterMask setLikeMask(null|\string|\Bitrix\Main\DB\SqlExpression $likeMask)
	 * @method bool hasLikeMask()
	 * @method bool isLikeMaskFilled()
	 * @method bool isLikeMaskChanged()
	 * @method null|\string remindActualLikeMask()
	 * @method null|\string requireLikeMask()
	 * @method \Bitrix\Security\FilterMask resetLikeMask()
	 * @method \Bitrix\Security\FilterMask unsetLikeMask()
	 * @method null|\string fillLikeMask()
	 * @method null|\string getPregMask()
	 * @method \Bitrix\Security\FilterMask setPregMask(null|\string|\Bitrix\Main\DB\SqlExpression $pregMask)
	 * @method bool hasPregMask()
	 * @method bool isPregMaskFilled()
	 * @method bool isPregMaskChanged()
	 * @method null|\string remindActualPregMask()
	 * @method null|\string requirePregMask()
	 * @method \Bitrix\Security\FilterMask resetPregMask()
	 * @method \Bitrix\Security\FilterMask unsetPregMask()
	 * @method null|\string fillPregMask()
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
	 * @method \Bitrix\Security\FilterMask set($fieldName, $value)
	 * @method \Bitrix\Security\FilterMask reset($fieldName)
	 * @method \Bitrix\Security\FilterMask unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\FilterMask wakeUp($data)
	 */
	class EO_FilterMask {
		/* @var \Bitrix\Security\FilterMaskTable */
		static public $dataClass = '\Bitrix\Security\FilterMaskTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * FilterMasks
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\string[] getSiteIdList()
	 * @method null|\string[] fillSiteId()
	 * @method null|\string[] getFilterMaskList()
	 * @method null|\string[] fillFilterMask()
	 * @method null|\string[] getLikeMaskList()
	 * @method null|\string[] fillLikeMask()
	 * @method null|\string[] getPregMaskList()
	 * @method null|\string[] fillPregMask()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\FilterMask $object)
	 * @method bool has(\Bitrix\Security\FilterMask $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\FilterMask getByPrimary($primary)
	 * @method \Bitrix\Security\FilterMask[] getAll()
	 * @method bool remove(\Bitrix\Security\FilterMask $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\FilterMasks wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\FilterMask current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\FilterMasks merge(?\Bitrix\Security\FilterMasks $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_FilterMask_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\FilterMaskTable */
		static public $dataClass = '\Bitrix\Security\FilterMaskTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_FilterMask_Result exec()
	 * @method \Bitrix\Security\FilterMask fetchObject()
	 * @method \Bitrix\Security\FilterMasks fetchCollection()
	 */
	class EO_FilterMask_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\FilterMask fetchObject()
	 * @method \Bitrix\Security\FilterMasks fetchCollection()
	 */
	class EO_FilterMask_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\FilterMask createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\FilterMasks createCollection()
	 * @method \Bitrix\Security\FilterMask wakeUpObject($row)
	 * @method \Bitrix\Security\FilterMasks wakeUpCollection($rows)
	 */
	class EO_FilterMask_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\FrameMaskTable:security/lib/framemasktable.php */
namespace Bitrix\Security {
	/**
	 * FrameMask
	 * @see \Bitrix\Security\FrameMaskTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\FrameMask setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getSort()
	 * @method \Bitrix\Security\FrameMask setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\FrameMask resetSort()
	 * @method \Bitrix\Security\FrameMask unsetSort()
	 * @method \int fillSort()
	 * @method null|\string getSiteId()
	 * @method \Bitrix\Security\FrameMask setSiteId(null|\string|\Bitrix\Main\DB\SqlExpression $siteId)
	 * @method bool hasSiteId()
	 * @method bool isSiteIdFilled()
	 * @method bool isSiteIdChanged()
	 * @method null|\string remindActualSiteId()
	 * @method null|\string requireSiteId()
	 * @method \Bitrix\Security\FrameMask resetSiteId()
	 * @method \Bitrix\Security\FrameMask unsetSiteId()
	 * @method null|\string fillSiteId()
	 * @method null|\string getFrameMask()
	 * @method \Bitrix\Security\FrameMask setFrameMask(null|\string|\Bitrix\Main\DB\SqlExpression $frameMask)
	 * @method bool hasFrameMask()
	 * @method bool isFrameMaskFilled()
	 * @method bool isFrameMaskChanged()
	 * @method null|\string remindActualFrameMask()
	 * @method null|\string requireFrameMask()
	 * @method \Bitrix\Security\FrameMask resetFrameMask()
	 * @method \Bitrix\Security\FrameMask unsetFrameMask()
	 * @method null|\string fillFrameMask()
	 * @method null|\string getLikeMask()
	 * @method \Bitrix\Security\FrameMask setLikeMask(null|\string|\Bitrix\Main\DB\SqlExpression $likeMask)
	 * @method bool hasLikeMask()
	 * @method bool isLikeMaskFilled()
	 * @method bool isLikeMaskChanged()
	 * @method null|\string remindActualLikeMask()
	 * @method null|\string requireLikeMask()
	 * @method \Bitrix\Security\FrameMask resetLikeMask()
	 * @method \Bitrix\Security\FrameMask unsetLikeMask()
	 * @method null|\string fillLikeMask()
	 * @method null|\string getPregMask()
	 * @method \Bitrix\Security\FrameMask setPregMask(null|\string|\Bitrix\Main\DB\SqlExpression $pregMask)
	 * @method bool hasPregMask()
	 * @method bool isPregMaskFilled()
	 * @method bool isPregMaskChanged()
	 * @method null|\string remindActualPregMask()
	 * @method null|\string requirePregMask()
	 * @method \Bitrix\Security\FrameMask resetPregMask()
	 * @method \Bitrix\Security\FrameMask unsetPregMask()
	 * @method null|\string fillPregMask()
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
	 * @method \Bitrix\Security\FrameMask set($fieldName, $value)
	 * @method \Bitrix\Security\FrameMask reset($fieldName)
	 * @method \Bitrix\Security\FrameMask unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\FrameMask wakeUp($data)
	 */
	class EO_FrameMask {
		/* @var \Bitrix\Security\FrameMaskTable */
		static public $dataClass = '\Bitrix\Security\FrameMaskTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * FrameMasks
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\string[] getSiteIdList()
	 * @method null|\string[] fillSiteId()
	 * @method null|\string[] getFrameMaskList()
	 * @method null|\string[] fillFrameMask()
	 * @method null|\string[] getLikeMaskList()
	 * @method null|\string[] fillLikeMask()
	 * @method null|\string[] getPregMaskList()
	 * @method null|\string[] fillPregMask()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\FrameMask $object)
	 * @method bool has(\Bitrix\Security\FrameMask $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\FrameMask getByPrimary($primary)
	 * @method \Bitrix\Security\FrameMask[] getAll()
	 * @method bool remove(\Bitrix\Security\FrameMask $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\FrameMasks wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\FrameMask current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\FrameMasks merge(?\Bitrix\Security\FrameMasks $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_FrameMask_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\FrameMaskTable */
		static public $dataClass = '\Bitrix\Security\FrameMaskTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_FrameMask_Result exec()
	 * @method \Bitrix\Security\FrameMask fetchObject()
	 * @method \Bitrix\Security\FrameMasks fetchCollection()
	 */
	class EO_FrameMask_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\FrameMask fetchObject()
	 * @method \Bitrix\Security\FrameMasks fetchCollection()
	 */
	class EO_FrameMask_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\FrameMask createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\FrameMasks createCollection()
	 * @method \Bitrix\Security\FrameMask wakeUpObject($row)
	 * @method \Bitrix\Security\FrameMasks wakeUpCollection($rows)
	 */
	class EO_FrameMask_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\IPRuleExclIPTable:security/lib/ipruleexcliptable.php */
namespace Bitrix\Security {
	/**
	 * IPRuleExclIP
	 * @see \Bitrix\Security\IPRuleExclIPTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getIpruleId()
	 * @method \Bitrix\Security\IPRuleExclIP setIpruleId(\int|\Bitrix\Main\DB\SqlExpression $ipruleId)
	 * @method bool hasIpruleId()
	 * @method bool isIpruleIdFilled()
	 * @method bool isIpruleIdChanged()
	 * @method \string getRuleIp()
	 * @method \Bitrix\Security\IPRuleExclIP setRuleIp(\string|\Bitrix\Main\DB\SqlExpression $ruleIp)
	 * @method bool hasRuleIp()
	 * @method bool isRuleIpFilled()
	 * @method bool isRuleIpChanged()
	 * @method \int getSort()
	 * @method \Bitrix\Security\IPRuleExclIP setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\IPRuleExclIP resetSort()
	 * @method \Bitrix\Security\IPRuleExclIP unsetSort()
	 * @method \int fillSort()
	 * @method null|\int getIpStart()
	 * @method \Bitrix\Security\IPRuleExclIP setIpStart(null|\int|\Bitrix\Main\DB\SqlExpression $ipStart)
	 * @method bool hasIpStart()
	 * @method bool isIpStartFilled()
	 * @method bool isIpStartChanged()
	 * @method null|\int remindActualIpStart()
	 * @method null|\int requireIpStart()
	 * @method \Bitrix\Security\IPRuleExclIP resetIpStart()
	 * @method \Bitrix\Security\IPRuleExclIP unsetIpStart()
	 * @method null|\int fillIpStart()
	 * @method null|\int getIpEnd()
	 * @method \Bitrix\Security\IPRuleExclIP setIpEnd(null|\int|\Bitrix\Main\DB\SqlExpression $ipEnd)
	 * @method bool hasIpEnd()
	 * @method bool isIpEndFilled()
	 * @method bool isIpEndChanged()
	 * @method null|\int remindActualIpEnd()
	 * @method null|\int requireIpEnd()
	 * @method \Bitrix\Security\IPRuleExclIP resetIpEnd()
	 * @method \Bitrix\Security\IPRuleExclIP unsetIpEnd()
	 * @method null|\int fillIpEnd()
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
	 * @method \Bitrix\Security\IPRuleExclIP set($fieldName, $value)
	 * @method \Bitrix\Security\IPRuleExclIP reset($fieldName)
	 * @method \Bitrix\Security\IPRuleExclIP unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\IPRuleExclIP wakeUp($data)
	 */
	class EO_IPRuleExclIP {
		/* @var \Bitrix\Security\IPRuleExclIPTable */
		static public $dataClass = '\Bitrix\Security\IPRuleExclIPTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * IPRuleExclIPs
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIpruleIdList()
	 * @method \string[] getRuleIpList()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\int[] getIpStartList()
	 * @method null|\int[] fillIpStart()
	 * @method null|\int[] getIpEndList()
	 * @method null|\int[] fillIpEnd()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\IPRuleExclIP $object)
	 * @method bool has(\Bitrix\Security\IPRuleExclIP $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleExclIP getByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleExclIP[] getAll()
	 * @method bool remove(\Bitrix\Security\IPRuleExclIP $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\IPRuleExclIPs wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\IPRuleExclIP current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\IPRuleExclIPs merge(?\Bitrix\Security\IPRuleExclIPs $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_IPRuleExclIP_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\IPRuleExclIPTable */
		static public $dataClass = '\Bitrix\Security\IPRuleExclIPTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_IPRuleExclIP_Result exec()
	 * @method \Bitrix\Security\IPRuleExclIP fetchObject()
	 * @method \Bitrix\Security\IPRuleExclIPs fetchCollection()
	 */
	class EO_IPRuleExclIP_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\IPRuleExclIP fetchObject()
	 * @method \Bitrix\Security\IPRuleExclIPs fetchCollection()
	 */
	class EO_IPRuleExclIP_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\IPRuleExclIP createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\IPRuleExclIPs createCollection()
	 * @method \Bitrix\Security\IPRuleExclIP wakeUpObject($row)
	 * @method \Bitrix\Security\IPRuleExclIPs wakeUpCollection($rows)
	 */
	class EO_IPRuleExclIP_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\IPRuleExclMaskTable:security/lib/ipruleexclmasktable.php */
namespace Bitrix\Security {
	/**
	 * IPRuleExclMask
	 * @see \Bitrix\Security\IPRuleExclMaskTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getIpruleId()
	 * @method \Bitrix\Security\IPRuleExclMask setIpruleId(\int|\Bitrix\Main\DB\SqlExpression $ipruleId)
	 * @method bool hasIpruleId()
	 * @method bool isIpruleIdFilled()
	 * @method bool isIpruleIdChanged()
	 * @method \string getRuleMask()
	 * @method \Bitrix\Security\IPRuleExclMask setRuleMask(\string|\Bitrix\Main\DB\SqlExpression $ruleMask)
	 * @method bool hasRuleMask()
	 * @method bool isRuleMaskFilled()
	 * @method bool isRuleMaskChanged()
	 * @method \int getSort()
	 * @method \Bitrix\Security\IPRuleExclMask setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\IPRuleExclMask resetSort()
	 * @method \Bitrix\Security\IPRuleExclMask unsetSort()
	 * @method \int fillSort()
	 * @method null|\string getLikeMask()
	 * @method \Bitrix\Security\IPRuleExclMask setLikeMask(null|\string|\Bitrix\Main\DB\SqlExpression $likeMask)
	 * @method bool hasLikeMask()
	 * @method bool isLikeMaskFilled()
	 * @method bool isLikeMaskChanged()
	 * @method null|\string remindActualLikeMask()
	 * @method null|\string requireLikeMask()
	 * @method \Bitrix\Security\IPRuleExclMask resetLikeMask()
	 * @method \Bitrix\Security\IPRuleExclMask unsetLikeMask()
	 * @method null|\string fillLikeMask()
	 * @method null|\string getPregMask()
	 * @method \Bitrix\Security\IPRuleExclMask setPregMask(null|\string|\Bitrix\Main\DB\SqlExpression $pregMask)
	 * @method bool hasPregMask()
	 * @method bool isPregMaskFilled()
	 * @method bool isPregMaskChanged()
	 * @method null|\string remindActualPregMask()
	 * @method null|\string requirePregMask()
	 * @method \Bitrix\Security\IPRuleExclMask resetPregMask()
	 * @method \Bitrix\Security\IPRuleExclMask unsetPregMask()
	 * @method null|\string fillPregMask()
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
	 * @method \Bitrix\Security\IPRuleExclMask set($fieldName, $value)
	 * @method \Bitrix\Security\IPRuleExclMask reset($fieldName)
	 * @method \Bitrix\Security\IPRuleExclMask unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\IPRuleExclMask wakeUp($data)
	 */
	class EO_IPRuleExclMask {
		/* @var \Bitrix\Security\IPRuleExclMaskTable */
		static public $dataClass = '\Bitrix\Security\IPRuleExclMaskTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * IPRuleExclMasks
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIpruleIdList()
	 * @method \string[] getRuleMaskList()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\string[] getLikeMaskList()
	 * @method null|\string[] fillLikeMask()
	 * @method null|\string[] getPregMaskList()
	 * @method null|\string[] fillPregMask()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\IPRuleExclMask $object)
	 * @method bool has(\Bitrix\Security\IPRuleExclMask $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleExclMask getByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleExclMask[] getAll()
	 * @method bool remove(\Bitrix\Security\IPRuleExclMask $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\IPRuleExclMasks wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\IPRuleExclMask current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\IPRuleExclMasks merge(?\Bitrix\Security\IPRuleExclMasks $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_IPRuleExclMask_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\IPRuleExclMaskTable */
		static public $dataClass = '\Bitrix\Security\IPRuleExclMaskTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_IPRuleExclMask_Result exec()
	 * @method \Bitrix\Security\IPRuleExclMask fetchObject()
	 * @method \Bitrix\Security\IPRuleExclMasks fetchCollection()
	 */
	class EO_IPRuleExclMask_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\IPRuleExclMask fetchObject()
	 * @method \Bitrix\Security\IPRuleExclMasks fetchCollection()
	 */
	class EO_IPRuleExclMask_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\IPRuleExclMask createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\IPRuleExclMasks createCollection()
	 * @method \Bitrix\Security\IPRuleExclMask wakeUpObject($row)
	 * @method \Bitrix\Security\IPRuleExclMasks wakeUpCollection($rows)
	 */
	class EO_IPRuleExclMask_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\IPRuleInclIPTable:security/lib/ipruleincliptable.php */
namespace Bitrix\Security {
	/**
	 * IPRuleInclIP
	 * @see \Bitrix\Security\IPRuleInclIPTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getIpruleId()
	 * @method \Bitrix\Security\IPRuleInclIP setIpruleId(\int|\Bitrix\Main\DB\SqlExpression $ipruleId)
	 * @method bool hasIpruleId()
	 * @method bool isIpruleIdFilled()
	 * @method bool isIpruleIdChanged()
	 * @method \string getRuleIp()
	 * @method \Bitrix\Security\IPRuleInclIP setRuleIp(\string|\Bitrix\Main\DB\SqlExpression $ruleIp)
	 * @method bool hasRuleIp()
	 * @method bool isRuleIpFilled()
	 * @method bool isRuleIpChanged()
	 * @method \int getSort()
	 * @method \Bitrix\Security\IPRuleInclIP setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\IPRuleInclIP resetSort()
	 * @method \Bitrix\Security\IPRuleInclIP unsetSort()
	 * @method \int fillSort()
	 * @method null|\int getIpStart()
	 * @method \Bitrix\Security\IPRuleInclIP setIpStart(null|\int|\Bitrix\Main\DB\SqlExpression $ipStart)
	 * @method bool hasIpStart()
	 * @method bool isIpStartFilled()
	 * @method bool isIpStartChanged()
	 * @method null|\int remindActualIpStart()
	 * @method null|\int requireIpStart()
	 * @method \Bitrix\Security\IPRuleInclIP resetIpStart()
	 * @method \Bitrix\Security\IPRuleInclIP unsetIpStart()
	 * @method null|\int fillIpStart()
	 * @method null|\int getIpEnd()
	 * @method \Bitrix\Security\IPRuleInclIP setIpEnd(null|\int|\Bitrix\Main\DB\SqlExpression $ipEnd)
	 * @method bool hasIpEnd()
	 * @method bool isIpEndFilled()
	 * @method bool isIpEndChanged()
	 * @method null|\int remindActualIpEnd()
	 * @method null|\int requireIpEnd()
	 * @method \Bitrix\Security\IPRuleInclIP resetIpEnd()
	 * @method \Bitrix\Security\IPRuleInclIP unsetIpEnd()
	 * @method null|\int fillIpEnd()
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
	 * @method \Bitrix\Security\IPRuleInclIP set($fieldName, $value)
	 * @method \Bitrix\Security\IPRuleInclIP reset($fieldName)
	 * @method \Bitrix\Security\IPRuleInclIP unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\IPRuleInclIP wakeUp($data)
	 */
	class EO_IPRuleInclIP {
		/* @var \Bitrix\Security\IPRuleInclIPTable */
		static public $dataClass = '\Bitrix\Security\IPRuleInclIPTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * IPRuleInclIPs
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIpruleIdList()
	 * @method \string[] getRuleIpList()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\int[] getIpStartList()
	 * @method null|\int[] fillIpStart()
	 * @method null|\int[] getIpEndList()
	 * @method null|\int[] fillIpEnd()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\IPRuleInclIP $object)
	 * @method bool has(\Bitrix\Security\IPRuleInclIP $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleInclIP getByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleInclIP[] getAll()
	 * @method bool remove(\Bitrix\Security\IPRuleInclIP $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\IPRuleInclIPs wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\IPRuleInclIP current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\IPRuleInclIPs merge(?\Bitrix\Security\IPRuleInclIPs $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_IPRuleInclIP_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\IPRuleInclIPTable */
		static public $dataClass = '\Bitrix\Security\IPRuleInclIPTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_IPRuleInclIP_Result exec()
	 * @method \Bitrix\Security\IPRuleInclIP fetchObject()
	 * @method \Bitrix\Security\IPRuleInclIPs fetchCollection()
	 */
	class EO_IPRuleInclIP_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\IPRuleInclIP fetchObject()
	 * @method \Bitrix\Security\IPRuleInclIPs fetchCollection()
	 */
	class EO_IPRuleInclIP_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\IPRuleInclIP createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\IPRuleInclIPs createCollection()
	 * @method \Bitrix\Security\IPRuleInclIP wakeUpObject($row)
	 * @method \Bitrix\Security\IPRuleInclIPs wakeUpCollection($rows)
	 */
	class EO_IPRuleInclIP_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\IPRuleInclMaskTable:security/lib/ipruleinclmasktable.php */
namespace Bitrix\Security {
	/**
	 * IPRuleInclMask
	 * @see \Bitrix\Security\IPRuleInclMaskTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getIpruleId()
	 * @method \Bitrix\Security\IPRuleInclMask setIpruleId(\int|\Bitrix\Main\DB\SqlExpression $ipruleId)
	 * @method bool hasIpruleId()
	 * @method bool isIpruleIdFilled()
	 * @method bool isIpruleIdChanged()
	 * @method \string getRuleMask()
	 * @method \Bitrix\Security\IPRuleInclMask setRuleMask(\string|\Bitrix\Main\DB\SqlExpression $ruleMask)
	 * @method bool hasRuleMask()
	 * @method bool isRuleMaskFilled()
	 * @method bool isRuleMaskChanged()
	 * @method \int getSort()
	 * @method \Bitrix\Security\IPRuleInclMask setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\IPRuleInclMask resetSort()
	 * @method \Bitrix\Security\IPRuleInclMask unsetSort()
	 * @method \int fillSort()
	 * @method null|\string getLikeMask()
	 * @method \Bitrix\Security\IPRuleInclMask setLikeMask(null|\string|\Bitrix\Main\DB\SqlExpression $likeMask)
	 * @method bool hasLikeMask()
	 * @method bool isLikeMaskFilled()
	 * @method bool isLikeMaskChanged()
	 * @method null|\string remindActualLikeMask()
	 * @method null|\string requireLikeMask()
	 * @method \Bitrix\Security\IPRuleInclMask resetLikeMask()
	 * @method \Bitrix\Security\IPRuleInclMask unsetLikeMask()
	 * @method null|\string fillLikeMask()
	 * @method null|\string getPregMask()
	 * @method \Bitrix\Security\IPRuleInclMask setPregMask(null|\string|\Bitrix\Main\DB\SqlExpression $pregMask)
	 * @method bool hasPregMask()
	 * @method bool isPregMaskFilled()
	 * @method bool isPregMaskChanged()
	 * @method null|\string remindActualPregMask()
	 * @method null|\string requirePregMask()
	 * @method \Bitrix\Security\IPRuleInclMask resetPregMask()
	 * @method \Bitrix\Security\IPRuleInclMask unsetPregMask()
	 * @method null|\string fillPregMask()
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
	 * @method \Bitrix\Security\IPRuleInclMask set($fieldName, $value)
	 * @method \Bitrix\Security\IPRuleInclMask reset($fieldName)
	 * @method \Bitrix\Security\IPRuleInclMask unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\IPRuleInclMask wakeUp($data)
	 */
	class EO_IPRuleInclMask {
		/* @var \Bitrix\Security\IPRuleInclMaskTable */
		static public $dataClass = '\Bitrix\Security\IPRuleInclMaskTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * IPRuleInclMasks
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIpruleIdList()
	 * @method \string[] getRuleMaskList()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\string[] getLikeMaskList()
	 * @method null|\string[] fillLikeMask()
	 * @method null|\string[] getPregMaskList()
	 * @method null|\string[] fillPregMask()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\IPRuleInclMask $object)
	 * @method bool has(\Bitrix\Security\IPRuleInclMask $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleInclMask getByPrimary($primary)
	 * @method \Bitrix\Security\IPRuleInclMask[] getAll()
	 * @method bool remove(\Bitrix\Security\IPRuleInclMask $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\IPRuleInclMasks wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\IPRuleInclMask current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\IPRuleInclMasks merge(?\Bitrix\Security\IPRuleInclMasks $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_IPRuleInclMask_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\IPRuleInclMaskTable */
		static public $dataClass = '\Bitrix\Security\IPRuleInclMaskTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_IPRuleInclMask_Result exec()
	 * @method \Bitrix\Security\IPRuleInclMask fetchObject()
	 * @method \Bitrix\Security\IPRuleInclMasks fetchCollection()
	 */
	class EO_IPRuleInclMask_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\IPRuleInclMask fetchObject()
	 * @method \Bitrix\Security\IPRuleInclMasks fetchCollection()
	 */
	class EO_IPRuleInclMask_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\IPRuleInclMask createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\IPRuleInclMasks createCollection()
	 * @method \Bitrix\Security\IPRuleInclMask wakeUpObject($row)
	 * @method \Bitrix\Security\IPRuleInclMasks wakeUpCollection($rows)
	 */
	class EO_IPRuleInclMask_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\IPRuleTable:security/lib/ipruletable.php */
namespace Bitrix\Security {
	/**
	 * IPRule
	 * @see \Bitrix\Security\IPRuleTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\IPRule setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getRuleType()
	 * @method \Bitrix\Security\IPRule setRuleType(\string|\Bitrix\Main\DB\SqlExpression $ruleType)
	 * @method bool hasRuleType()
	 * @method bool isRuleTypeFilled()
	 * @method bool isRuleTypeChanged()
	 * @method \string remindActualRuleType()
	 * @method \string requireRuleType()
	 * @method \Bitrix\Security\IPRule resetRuleType()
	 * @method \Bitrix\Security\IPRule unsetRuleType()
	 * @method \string fillRuleType()
	 * @method \string getActive()
	 * @method \Bitrix\Security\IPRule setActive(\string|\Bitrix\Main\DB\SqlExpression $active)
	 * @method bool hasActive()
	 * @method bool isActiveFilled()
	 * @method bool isActiveChanged()
	 * @method \string remindActualActive()
	 * @method \string requireActive()
	 * @method \Bitrix\Security\IPRule resetActive()
	 * @method \Bitrix\Security\IPRule unsetActive()
	 * @method \string fillActive()
	 * @method \string getAdminSection()
	 * @method \Bitrix\Security\IPRule setAdminSection(\string|\Bitrix\Main\DB\SqlExpression $adminSection)
	 * @method bool hasAdminSection()
	 * @method bool isAdminSectionFilled()
	 * @method bool isAdminSectionChanged()
	 * @method \string remindActualAdminSection()
	 * @method \string requireAdminSection()
	 * @method \Bitrix\Security\IPRule resetAdminSection()
	 * @method \Bitrix\Security\IPRule unsetAdminSection()
	 * @method \string fillAdminSection()
	 * @method null|\string getSiteId()
	 * @method \Bitrix\Security\IPRule setSiteId(null|\string|\Bitrix\Main\DB\SqlExpression $siteId)
	 * @method bool hasSiteId()
	 * @method bool isSiteIdFilled()
	 * @method bool isSiteIdChanged()
	 * @method null|\string remindActualSiteId()
	 * @method null|\string requireSiteId()
	 * @method \Bitrix\Security\IPRule resetSiteId()
	 * @method \Bitrix\Security\IPRule unsetSiteId()
	 * @method null|\string fillSiteId()
	 * @method \int getSort()
	 * @method \Bitrix\Security\IPRule setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\IPRule resetSort()
	 * @method \Bitrix\Security\IPRule unsetSort()
	 * @method \int fillSort()
	 * @method null|\Bitrix\Main\Type\DateTime getActiveFrom()
	 * @method \Bitrix\Security\IPRule setActiveFrom(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $activeFrom)
	 * @method bool hasActiveFrom()
	 * @method bool isActiveFromFilled()
	 * @method bool isActiveFromChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualActiveFrom()
	 * @method null|\Bitrix\Main\Type\DateTime requireActiveFrom()
	 * @method \Bitrix\Security\IPRule resetActiveFrom()
	 * @method \Bitrix\Security\IPRule unsetActiveFrom()
	 * @method null|\Bitrix\Main\Type\DateTime fillActiveFrom()
	 * @method null|\int getActiveFromTimestamp()
	 * @method \Bitrix\Security\IPRule setActiveFromTimestamp(null|\int|\Bitrix\Main\DB\SqlExpression $activeFromTimestamp)
	 * @method bool hasActiveFromTimestamp()
	 * @method bool isActiveFromTimestampFilled()
	 * @method bool isActiveFromTimestampChanged()
	 * @method null|\int remindActualActiveFromTimestamp()
	 * @method null|\int requireActiveFromTimestamp()
	 * @method \Bitrix\Security\IPRule resetActiveFromTimestamp()
	 * @method \Bitrix\Security\IPRule unsetActiveFromTimestamp()
	 * @method null|\int fillActiveFromTimestamp()
	 * @method null|\Bitrix\Main\Type\DateTime getActiveTo()
	 * @method \Bitrix\Security\IPRule setActiveTo(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $activeTo)
	 * @method bool hasActiveTo()
	 * @method bool isActiveToFilled()
	 * @method bool isActiveToChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualActiveTo()
	 * @method null|\Bitrix\Main\Type\DateTime requireActiveTo()
	 * @method \Bitrix\Security\IPRule resetActiveTo()
	 * @method \Bitrix\Security\IPRule unsetActiveTo()
	 * @method null|\Bitrix\Main\Type\DateTime fillActiveTo()
	 * @method null|\int getActiveToTimestamp()
	 * @method \Bitrix\Security\IPRule setActiveToTimestamp(null|\int|\Bitrix\Main\DB\SqlExpression $activeToTimestamp)
	 * @method bool hasActiveToTimestamp()
	 * @method bool isActiveToTimestampFilled()
	 * @method bool isActiveToTimestampChanged()
	 * @method null|\int remindActualActiveToTimestamp()
	 * @method null|\int requireActiveToTimestamp()
	 * @method \Bitrix\Security\IPRule resetActiveToTimestamp()
	 * @method \Bitrix\Security\IPRule unsetActiveToTimestamp()
	 * @method null|\int fillActiveToTimestamp()
	 * @method null|\string getName()
	 * @method \Bitrix\Security\IPRule setName(null|\string|\Bitrix\Main\DB\SqlExpression $name)
	 * @method bool hasName()
	 * @method bool isNameFilled()
	 * @method bool isNameChanged()
	 * @method null|\string remindActualName()
	 * @method null|\string requireName()
	 * @method \Bitrix\Security\IPRule resetName()
	 * @method \Bitrix\Security\IPRule unsetName()
	 * @method null|\string fillName()
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
	 * @method \Bitrix\Security\IPRule set($fieldName, $value)
	 * @method \Bitrix\Security\IPRule reset($fieldName)
	 * @method \Bitrix\Security\IPRule unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\IPRule wakeUp($data)
	 */
	class EO_IPRule {
		/* @var \Bitrix\Security\IPRuleTable */
		static public $dataClass = '\Bitrix\Security\IPRuleTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * IPRules
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getRuleTypeList()
	 * @method \string[] fillRuleType()
	 * @method \string[] getActiveList()
	 * @method \string[] fillActive()
	 * @method \string[] getAdminSectionList()
	 * @method \string[] fillAdminSection()
	 * @method null|\string[] getSiteIdList()
	 * @method null|\string[] fillSiteId()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method null|\Bitrix\Main\Type\DateTime[] getActiveFromList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillActiveFrom()
	 * @method null|\int[] getActiveFromTimestampList()
	 * @method null|\int[] fillActiveFromTimestamp()
	 * @method null|\Bitrix\Main\Type\DateTime[] getActiveToList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillActiveTo()
	 * @method null|\int[] getActiveToTimestampList()
	 * @method null|\int[] fillActiveToTimestamp()
	 * @method null|\string[] getNameList()
	 * @method null|\string[] fillName()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\IPRule $object)
	 * @method bool has(\Bitrix\Security\IPRule $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\IPRule getByPrimary($primary)
	 * @method \Bitrix\Security\IPRule[] getAll()
	 * @method bool remove(\Bitrix\Security\IPRule $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\IPRules wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\IPRule current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\IPRules merge(?\Bitrix\Security\IPRules $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_IPRule_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\IPRuleTable */
		static public $dataClass = '\Bitrix\Security\IPRuleTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_IPRule_Result exec()
	 * @method \Bitrix\Security\IPRule fetchObject()
	 * @method \Bitrix\Security\IPRules fetchCollection()
	 */
	class EO_IPRule_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\IPRule fetchObject()
	 * @method \Bitrix\Security\IPRules fetchCollection()
	 */
	class EO_IPRule_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\IPRule createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\IPRules createCollection()
	 * @method \Bitrix\Security\IPRule wakeUpObject($row)
	 * @method \Bitrix\Security\IPRules wakeUpCollection($rows)
	 */
	class EO_IPRule_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\Mfa\RecoveryCodesTable:security/lib/mfa/recoverycodes.php */
namespace Bitrix\Security\Mfa {
	/**
	 * EO_RecoveryCodes
	 * @see \Bitrix\Security\Mfa\RecoveryCodesTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes resetUserId()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getCode()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes resetCode()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unsetCode()
	 * @method \string fillCode()
	 * @method \boolean getUsed()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setUsed(\boolean|\Bitrix\Main\DB\SqlExpression $used)
	 * @method bool hasUsed()
	 * @method bool isUsedFilled()
	 * @method bool isUsedChanged()
	 * @method \boolean remindActualUsed()
	 * @method \boolean requireUsed()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes resetUsed()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unsetUsed()
	 * @method \boolean fillUsed()
	 * @method \Bitrix\Main\Type\DateTime getUsingDate()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setUsingDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $usingDate)
	 * @method bool hasUsingDate()
	 * @method bool isUsingDateFilled()
	 * @method bool isUsingDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualUsingDate()
	 * @method \Bitrix\Main\Type\DateTime requireUsingDate()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes resetUsingDate()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unsetUsingDate()
	 * @method \Bitrix\Main\Type\DateTime fillUsingDate()
	 * @method \string getUsingIp()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setUsingIp(\string|\Bitrix\Main\DB\SqlExpression $usingIp)
	 * @method bool hasUsingIp()
	 * @method bool isUsingIpFilled()
	 * @method bool isUsingIpChanged()
	 * @method \string remindActualUsingIp()
	 * @method \string requireUsingIp()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes resetUsingIp()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unsetUsingIp()
	 * @method \string fillUsingIp()
	 * @method \Bitrix\Main\EO_User getUser()
	 * @method \Bitrix\Main\EO_User remindActualUser()
	 * @method \Bitrix\Main\EO_User requireUser()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes setUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes resetUser()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unsetUser()
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
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes set($fieldName, $value)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes reset($fieldName)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\Mfa\EO_RecoveryCodes wakeUp($data)
	 */
	class EO_RecoveryCodes {
		/* @var \Bitrix\Security\Mfa\RecoveryCodesTable */
		static public $dataClass = '\Bitrix\Security\Mfa\RecoveryCodesTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security\Mfa {
	/**
	 * EO_RecoveryCodes_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \boolean[] getUsedList()
	 * @method \boolean[] fillUsed()
	 * @method \Bitrix\Main\Type\DateTime[] getUsingDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillUsingDate()
	 * @method \string[] getUsingIpList()
	 * @method \string[] fillUsingIp()
	 * @method \Bitrix\Main\EO_User[] getUserList()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection getUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\Mfa\EO_RecoveryCodes $object)
	 * @method bool has(\Bitrix\Security\Mfa\EO_RecoveryCodes $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes getByPrimary($primary)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes[] getAll()
	 * @method bool remove(\Bitrix\Security\Mfa\EO_RecoveryCodes $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection merge(?\Bitrix\Security\Mfa\EO_RecoveryCodes_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_RecoveryCodes_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\Mfa\RecoveryCodesTable */
		static public $dataClass = '\Bitrix\Security\Mfa\RecoveryCodesTable';
	}
}
namespace Bitrix\Security\Mfa {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RecoveryCodes_Result exec()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes fetchObject()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection fetchCollection()
	 */
	class EO_RecoveryCodes_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes fetchObject()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection fetchCollection()
	 */
	class EO_RecoveryCodes_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection createCollection()
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes wakeUpObject($row)
	 * @method \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection wakeUpCollection($rows)
	 */
	class EO_RecoveryCodes_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\Mfa\UserTable:security/lib/mfa/user.php */
namespace Bitrix\Security\Mfa {
	/**
	 * EO_User
	 * @see \Bitrix\Security\Mfa\UserTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getUserId()
	 * @method \Bitrix\Security\Mfa\EO_User setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \Bitrix\Main\EO_User getUser()
	 * @method \Bitrix\Main\EO_User remindActualUser()
	 * @method \Bitrix\Main\EO_User requireUser()
	 * @method \Bitrix\Security\Mfa\EO_User setUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Security\Mfa\EO_User resetUser()
	 * @method \Bitrix\Security\Mfa\EO_User unsetUser()
	 * @method bool hasUser()
	 * @method bool isUserFilled()
	 * @method bool isUserChanged()
	 * @method \Bitrix\Main\EO_User fillUser()
	 * @method \boolean getActive()
	 * @method \Bitrix\Security\Mfa\EO_User setActive(\boolean|\Bitrix\Main\DB\SqlExpression $active)
	 * @method bool hasActive()
	 * @method bool isActiveFilled()
	 * @method bool isActiveChanged()
	 * @method \boolean remindActualActive()
	 * @method \boolean requireActive()
	 * @method \Bitrix\Security\Mfa\EO_User resetActive()
	 * @method \Bitrix\Security\Mfa\EO_User unsetActive()
	 * @method \boolean fillActive()
	 * @method \string getSecret()
	 * @method \Bitrix\Security\Mfa\EO_User setSecret(\string|\Bitrix\Main\DB\SqlExpression $secret)
	 * @method bool hasSecret()
	 * @method bool isSecretFilled()
	 * @method bool isSecretChanged()
	 * @method \string remindActualSecret()
	 * @method \string requireSecret()
	 * @method \Bitrix\Security\Mfa\EO_User resetSecret()
	 * @method \Bitrix\Security\Mfa\EO_User unsetSecret()
	 * @method \string fillSecret()
	 * @method \string getParams()
	 * @method \Bitrix\Security\Mfa\EO_User setParams(\string|\Bitrix\Main\DB\SqlExpression $params)
	 * @method bool hasParams()
	 * @method bool isParamsFilled()
	 * @method bool isParamsChanged()
	 * @method \string remindActualParams()
	 * @method \string requireParams()
	 * @method \Bitrix\Security\Mfa\EO_User resetParams()
	 * @method \Bitrix\Security\Mfa\EO_User unsetParams()
	 * @method \string fillParams()
	 * @method \string getType()
	 * @method \Bitrix\Security\Mfa\EO_User setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Security\Mfa\EO_User resetType()
	 * @method \Bitrix\Security\Mfa\EO_User unsetType()
	 * @method \string fillType()
	 * @method \int getAttempts()
	 * @method \Bitrix\Security\Mfa\EO_User setAttempts(\int|\Bitrix\Main\DB\SqlExpression $attempts)
	 * @method bool hasAttempts()
	 * @method bool isAttemptsFilled()
	 * @method bool isAttemptsChanged()
	 * @method \int remindActualAttempts()
	 * @method \int requireAttempts()
	 * @method \Bitrix\Security\Mfa\EO_User resetAttempts()
	 * @method \Bitrix\Security\Mfa\EO_User unsetAttempts()
	 * @method \int fillAttempts()
	 * @method \Bitrix\Main\Type\DateTime getInitialDate()
	 * @method \Bitrix\Security\Mfa\EO_User setInitialDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $initialDate)
	 * @method bool hasInitialDate()
	 * @method bool isInitialDateFilled()
	 * @method bool isInitialDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualInitialDate()
	 * @method \Bitrix\Main\Type\DateTime requireInitialDate()
	 * @method \Bitrix\Security\Mfa\EO_User resetInitialDate()
	 * @method \Bitrix\Security\Mfa\EO_User unsetInitialDate()
	 * @method \Bitrix\Main\Type\DateTime fillInitialDate()
	 * @method \boolean getSkipMandatory()
	 * @method \Bitrix\Security\Mfa\EO_User setSkipMandatory(\boolean|\Bitrix\Main\DB\SqlExpression $skipMandatory)
	 * @method bool hasSkipMandatory()
	 * @method bool isSkipMandatoryFilled()
	 * @method bool isSkipMandatoryChanged()
	 * @method \boolean remindActualSkipMandatory()
	 * @method \boolean requireSkipMandatory()
	 * @method \Bitrix\Security\Mfa\EO_User resetSkipMandatory()
	 * @method \Bitrix\Security\Mfa\EO_User unsetSkipMandatory()
	 * @method \boolean fillSkipMandatory()
	 * @method \Bitrix\Main\Type\DateTime getDeactivateUntil()
	 * @method \Bitrix\Security\Mfa\EO_User setDeactivateUntil(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $deactivateUntil)
	 * @method bool hasDeactivateUntil()
	 * @method bool isDeactivateUntilFilled()
	 * @method bool isDeactivateUntilChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDeactivateUntil()
	 * @method \Bitrix\Main\Type\DateTime requireDeactivateUntil()
	 * @method \Bitrix\Security\Mfa\EO_User resetDeactivateUntil()
	 * @method \Bitrix\Security\Mfa\EO_User unsetDeactivateUntil()
	 * @method \Bitrix\Main\Type\DateTime fillDeactivateUntil()
	 * @method array getInitParams()
	 * @method \Bitrix\Security\Mfa\EO_User setInitParams(array|\Bitrix\Main\DB\SqlExpression $initParams)
	 * @method bool hasInitParams()
	 * @method bool isInitParamsFilled()
	 * @method bool isInitParamsChanged()
	 * @method array remindActualInitParams()
	 * @method array requireInitParams()
	 * @method \Bitrix\Security\Mfa\EO_User resetInitParams()
	 * @method \Bitrix\Security\Mfa\EO_User unsetInitParams()
	 * @method array fillInitParams()
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
	 * @method \Bitrix\Security\Mfa\EO_User set($fieldName, $value)
	 * @method \Bitrix\Security\Mfa\EO_User reset($fieldName)
	 * @method \Bitrix\Security\Mfa\EO_User unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\Mfa\EO_User wakeUp($data)
	 */
	class EO_User {
		/* @var \Bitrix\Security\Mfa\UserTable */
		static public $dataClass = '\Bitrix\Security\Mfa\UserTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security\Mfa {
	/**
	 * EO_User_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getUserIdList()
	 * @method \Bitrix\Main\EO_User[] getUserList()
	 * @method \Bitrix\Security\Mfa\EO_User_Collection getUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUser()
	 * @method \boolean[] getActiveList()
	 * @method \boolean[] fillActive()
	 * @method \string[] getSecretList()
	 * @method \string[] fillSecret()
	 * @method \string[] getParamsList()
	 * @method \string[] fillParams()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \int[] getAttemptsList()
	 * @method \int[] fillAttempts()
	 * @method \Bitrix\Main\Type\DateTime[] getInitialDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillInitialDate()
	 * @method \boolean[] getSkipMandatoryList()
	 * @method \boolean[] fillSkipMandatory()
	 * @method \Bitrix\Main\Type\DateTime[] getDeactivateUntilList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDeactivateUntil()
	 * @method array[] getInitParamsList()
	 * @method array[] fillInitParams()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\Mfa\EO_User $object)
	 * @method bool has(\Bitrix\Security\Mfa\EO_User $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\Mfa\EO_User getByPrimary($primary)
	 * @method \Bitrix\Security\Mfa\EO_User[] getAll()
	 * @method bool remove(\Bitrix\Security\Mfa\EO_User $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\Mfa\EO_User_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\Mfa\EO_User current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\Mfa\EO_User_Collection merge(?\Bitrix\Security\Mfa\EO_User_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_User_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\Mfa\UserTable */
		static public $dataClass = '\Bitrix\Security\Mfa\UserTable';
	}
}
namespace Bitrix\Security\Mfa {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_User_Result exec()
	 * @method \Bitrix\Security\Mfa\EO_User fetchObject()
	 * @method \Bitrix\Security\Mfa\EO_User_Collection fetchCollection()
	 */
	class EO_User_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\Mfa\EO_User fetchObject()
	 * @method \Bitrix\Security\Mfa\EO_User_Collection fetchCollection()
	 */
	class EO_User_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\Mfa\EO_User createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\Mfa\EO_User_Collection createCollection()
	 * @method \Bitrix\Security\Mfa\EO_User wakeUpObject($row)
	 * @method \Bitrix\Security\Mfa\EO_User_Collection wakeUpCollection($rows)
	 */
	class EO_User_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\RedirectRuleTable:security/lib/redirectruletable.php */
namespace Bitrix\Security {
	/**
	 * RedirectRule
	 * @see \Bitrix\Security\RedirectRuleTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getIsSystem()
	 * @method \Bitrix\Security\RedirectRule setIsSystem(\string|\Bitrix\Main\DB\SqlExpression $isSystem)
	 * @method bool hasIsSystem()
	 * @method bool isIsSystemFilled()
	 * @method bool isIsSystemChanged()
	 * @method \string remindActualIsSystem()
	 * @method \string requireIsSystem()
	 * @method \Bitrix\Security\RedirectRule resetIsSystem()
	 * @method \Bitrix\Security\RedirectRule unsetIsSystem()
	 * @method \string fillIsSystem()
	 * @method \int getSort()
	 * @method \Bitrix\Security\RedirectRule setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Security\RedirectRule resetSort()
	 * @method \Bitrix\Security\RedirectRule unsetSort()
	 * @method \int fillSort()
	 * @method \string getUrl()
	 * @method \Bitrix\Security\RedirectRule setUrl(\string|\Bitrix\Main\DB\SqlExpression $url)
	 * @method bool hasUrl()
	 * @method bool isUrlFilled()
	 * @method bool isUrlChanged()
	 * @method \string getParameterName()
	 * @method \Bitrix\Security\RedirectRule setParameterName(\string|\Bitrix\Main\DB\SqlExpression $parameterName)
	 * @method bool hasParameterName()
	 * @method bool isParameterNameFilled()
	 * @method bool isParameterNameChanged()
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
	 * @method \Bitrix\Security\RedirectRule set($fieldName, $value)
	 * @method \Bitrix\Security\RedirectRule reset($fieldName)
	 * @method \Bitrix\Security\RedirectRule unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\RedirectRule wakeUp($data)
	 */
	class EO_RedirectRule {
		/* @var \Bitrix\Security\RedirectRuleTable */
		static public $dataClass = '\Bitrix\Security\RedirectRuleTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * RedirectRules
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIsSystemList()
	 * @method \string[] fillIsSystem()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \string[] getUrlList()
	 * @method \string[] getParameterNameList()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\RedirectRule $object)
	 * @method bool has(\Bitrix\Security\RedirectRule $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\RedirectRule getByPrimary($primary)
	 * @method \Bitrix\Security\RedirectRule[] getAll()
	 * @method bool remove(\Bitrix\Security\RedirectRule $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\RedirectRules wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\RedirectRule current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\RedirectRules merge(?\Bitrix\Security\RedirectRules $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_RedirectRule_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\RedirectRuleTable */
		static public $dataClass = '\Bitrix\Security\RedirectRuleTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RedirectRule_Result exec()
	 * @method \Bitrix\Security\RedirectRule fetchObject()
	 * @method \Bitrix\Security\RedirectRules fetchCollection()
	 */
	class EO_RedirectRule_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\RedirectRule fetchObject()
	 * @method \Bitrix\Security\RedirectRules fetchCollection()
	 */
	class EO_RedirectRule_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\RedirectRule createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\RedirectRules createCollection()
	 * @method \Bitrix\Security\RedirectRule wakeUpObject($row)
	 * @method \Bitrix\Security\RedirectRules wakeUpCollection($rows)
	 */
	class EO_RedirectRule_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\SessionTable:security/lib/sessiontable.php */
namespace Bitrix\Security {
	/**
	 * EO_Session
	 * @see \Bitrix\Security\SessionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getSessionId()
	 * @method \Bitrix\Security\EO_Session setSessionId(\string|\Bitrix\Main\DB\SqlExpression $sessionId)
	 * @method bool hasSessionId()
	 * @method bool isSessionIdFilled()
	 * @method bool isSessionIdChanged()
	 * @method \Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\Security\EO_Session setTimestampX(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method \Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\Security\EO_Session resetTimestampX()
	 * @method \Bitrix\Security\EO_Session unsetTimestampX()
	 * @method \Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method \string getSessionData()
	 * @method \Bitrix\Security\EO_Session setSessionData(\string|\Bitrix\Main\DB\SqlExpression $sessionData)
	 * @method bool hasSessionData()
	 * @method bool isSessionDataFilled()
	 * @method bool isSessionDataChanged()
	 * @method \string remindActualSessionData()
	 * @method \string requireSessionData()
	 * @method \Bitrix\Security\EO_Session resetSessionData()
	 * @method \Bitrix\Security\EO_Session unsetSessionData()
	 * @method \string fillSessionData()
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
	 * @method \Bitrix\Security\EO_Session set($fieldName, $value)
	 * @method \Bitrix\Security\EO_Session reset($fieldName)
	 * @method \Bitrix\Security\EO_Session unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\EO_Session wakeUp($data)
	 */
	class EO_Session {
		/* @var \Bitrix\Security\SessionTable */
		static public $dataClass = '\Bitrix\Security\SessionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * EO_Session_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getSessionIdList()
	 * @method \Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method \Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method \string[] getSessionDataList()
	 * @method \string[] fillSessionData()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\EO_Session $object)
	 * @method bool has(\Bitrix\Security\EO_Session $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\EO_Session getByPrimary($primary)
	 * @method \Bitrix\Security\EO_Session[] getAll()
	 * @method bool remove(\Bitrix\Security\EO_Session $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\EO_Session_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\EO_Session current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\EO_Session_Collection merge(?\Bitrix\Security\EO_Session_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_Session_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\SessionTable */
		static public $dataClass = '\Bitrix\Security\SessionTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Session_Result exec()
	 * @method \Bitrix\Security\EO_Session fetchObject()
	 * @method \Bitrix\Security\EO_Session_Collection fetchCollection()
	 */
	class EO_Session_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\EO_Session fetchObject()
	 * @method \Bitrix\Security\EO_Session_Collection fetchCollection()
	 */
	class EO_Session_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\EO_Session createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\EO_Session_Collection createCollection()
	 * @method \Bitrix\Security\EO_Session wakeUpObject($row)
	 * @method \Bitrix\Security\EO_Session_Collection wakeUpCollection($rows)
	 */
	class EO_Session_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\SiteCheckTable:security/lib/sitechecktable.php */
namespace Bitrix\Security {
	/**
	 * SiteCheck
	 * @see \Bitrix\Security\SiteCheckTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\SiteCheck setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method null|\Bitrix\Main\Type\DateTime getTestDate()
	 * @method \Bitrix\Security\SiteCheck setTestDate(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $testDate)
	 * @method bool hasTestDate()
	 * @method bool isTestDateFilled()
	 * @method bool isTestDateChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualTestDate()
	 * @method null|\Bitrix\Main\Type\DateTime requireTestDate()
	 * @method \Bitrix\Security\SiteCheck resetTestDate()
	 * @method \Bitrix\Security\SiteCheck unsetTestDate()
	 * @method null|\Bitrix\Main\Type\DateTime fillTestDate()
	 * @method null|\string getResults()
	 * @method \Bitrix\Security\SiteCheck setResults(null|\string|\Bitrix\Main\DB\SqlExpression $results)
	 * @method bool hasResults()
	 * @method bool isResultsFilled()
	 * @method bool isResultsChanged()
	 * @method null|\string remindActualResults()
	 * @method null|\string requireResults()
	 * @method \Bitrix\Security\SiteCheck resetResults()
	 * @method \Bitrix\Security\SiteCheck unsetResults()
	 * @method null|\string fillResults()
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
	 * @method \Bitrix\Security\SiteCheck set($fieldName, $value)
	 * @method \Bitrix\Security\SiteCheck reset($fieldName)
	 * @method \Bitrix\Security\SiteCheck unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\SiteCheck wakeUp($data)
	 */
	class EO_SiteCheck {
		/* @var \Bitrix\Security\SiteCheckTable */
		static public $dataClass = '\Bitrix\Security\SiteCheckTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * SiteChecks
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method null|\Bitrix\Main\Type\DateTime[] getTestDateList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillTestDate()
	 * @method null|\string[] getResultsList()
	 * @method null|\string[] fillResults()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\SiteCheck $object)
	 * @method bool has(\Bitrix\Security\SiteCheck $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\SiteCheck getByPrimary($primary)
	 * @method \Bitrix\Security\SiteCheck[] getAll()
	 * @method bool remove(\Bitrix\Security\SiteCheck $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\SiteChecks wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\SiteCheck current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\SiteChecks merge(?\Bitrix\Security\SiteChecks $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_SiteCheck_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\SiteCheckTable */
		static public $dataClass = '\Bitrix\Security\SiteCheckTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_SiteCheck_Result exec()
	 * @method \Bitrix\Security\SiteCheck fetchObject()
	 * @method \Bitrix\Security\SiteChecks fetchCollection()
	 */
	class EO_SiteCheck_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\SiteCheck fetchObject()
	 * @method \Bitrix\Security\SiteChecks fetchCollection()
	 */
	class EO_SiteCheck_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\SiteCheck createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\SiteChecks createCollection()
	 * @method \Bitrix\Security\SiteCheck wakeUpObject($row)
	 * @method \Bitrix\Security\SiteChecks wakeUpCollection($rows)
	 */
	class EO_SiteCheck_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\VirusTable:security/lib/virustable.php */
namespace Bitrix\Security {
	/**
	 * Virus
	 * @see \Bitrix\Security\VirusTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getId()
	 * @method \Bitrix\Security\Virus setId(\string|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method null|\Bitrix\Main\Type\DateTime getTimestampX()
	 * @method \Bitrix\Security\Virus setTimestampX(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $timestampX)
	 * @method bool hasTimestampX()
	 * @method bool isTimestampXFilled()
	 * @method bool isTimestampXChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualTimestampX()
	 * @method null|\Bitrix\Main\Type\DateTime requireTimestampX()
	 * @method \Bitrix\Security\Virus resetTimestampX()
	 * @method \Bitrix\Security\Virus unsetTimestampX()
	 * @method null|\Bitrix\Main\Type\DateTime fillTimestampX()
	 * @method null|\string getSiteId()
	 * @method \Bitrix\Security\Virus setSiteId(null|\string|\Bitrix\Main\DB\SqlExpression $siteId)
	 * @method bool hasSiteId()
	 * @method bool isSiteIdFilled()
	 * @method bool isSiteIdChanged()
	 * @method null|\string remindActualSiteId()
	 * @method null|\string requireSiteId()
	 * @method \Bitrix\Security\Virus resetSiteId()
	 * @method \Bitrix\Security\Virus unsetSiteId()
	 * @method null|\string fillSiteId()
	 * @method \string getSent()
	 * @method \Bitrix\Security\Virus setSent(\string|\Bitrix\Main\DB\SqlExpression $sent)
	 * @method bool hasSent()
	 * @method bool isSentFilled()
	 * @method bool isSentChanged()
	 * @method \string remindActualSent()
	 * @method \string requireSent()
	 * @method \Bitrix\Security\Virus resetSent()
	 * @method \Bitrix\Security\Virus unsetSent()
	 * @method \string fillSent()
	 * @method \string getInfo()
	 * @method \Bitrix\Security\Virus setInfo(\string|\Bitrix\Main\DB\SqlExpression $info)
	 * @method bool hasInfo()
	 * @method bool isInfoFilled()
	 * @method bool isInfoChanged()
	 * @method \string remindActualInfo()
	 * @method \string requireInfo()
	 * @method \Bitrix\Security\Virus resetInfo()
	 * @method \Bitrix\Security\Virus unsetInfo()
	 * @method \string fillInfo()
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
	 * @method \Bitrix\Security\Virus set($fieldName, $value)
	 * @method \Bitrix\Security\Virus reset($fieldName)
	 * @method \Bitrix\Security\Virus unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\Virus wakeUp($data)
	 */
	class EO_Virus {
		/* @var \Bitrix\Security\VirusTable */
		static public $dataClass = '\Bitrix\Security\VirusTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * Viruss
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getIdList()
	 * @method null|\Bitrix\Main\Type\DateTime[] getTimestampXList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillTimestampX()
	 * @method null|\string[] getSiteIdList()
	 * @method null|\string[] fillSiteId()
	 * @method \string[] getSentList()
	 * @method \string[] fillSent()
	 * @method \string[] getInfoList()
	 * @method \string[] fillInfo()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\Virus $object)
	 * @method bool has(\Bitrix\Security\Virus $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\Virus getByPrimary($primary)
	 * @method \Bitrix\Security\Virus[] getAll()
	 * @method bool remove(\Bitrix\Security\Virus $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\Viruss wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\Virus current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\Viruss merge(?\Bitrix\Security\Viruss $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_Virus_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\VirusTable */
		static public $dataClass = '\Bitrix\Security\VirusTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Virus_Result exec()
	 * @method \Bitrix\Security\Virus fetchObject()
	 * @method \Bitrix\Security\Viruss fetchCollection()
	 */
	class EO_Virus_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\Virus fetchObject()
	 * @method \Bitrix\Security\Viruss fetchCollection()
	 */
	class EO_Virus_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\Virus createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\Viruss createCollection()
	 * @method \Bitrix\Security\Virus wakeUpObject($row)
	 * @method \Bitrix\Security\Viruss wakeUpCollection($rows)
	 */
	class EO_Virus_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\WhiteListTable:security/lib/whitelisttable.php */
namespace Bitrix\Security {
	/**
	 * WhiteList
	 * @see \Bitrix\Security\WhiteListTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\WhiteList setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getWhiteSubstr()
	 * @method \Bitrix\Security\WhiteList setWhiteSubstr(\string|\Bitrix\Main\DB\SqlExpression $whiteSubstr)
	 * @method bool hasWhiteSubstr()
	 * @method bool isWhiteSubstrFilled()
	 * @method bool isWhiteSubstrChanged()
	 * @method \string remindActualWhiteSubstr()
	 * @method \string requireWhiteSubstr()
	 * @method \Bitrix\Security\WhiteList resetWhiteSubstr()
	 * @method \Bitrix\Security\WhiteList unsetWhiteSubstr()
	 * @method \string fillWhiteSubstr()
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
	 * @method \Bitrix\Security\WhiteList set($fieldName, $value)
	 * @method \Bitrix\Security\WhiteList reset($fieldName)
	 * @method \Bitrix\Security\WhiteList unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\WhiteList wakeUp($data)
	 */
	class EO_WhiteList {
		/* @var \Bitrix\Security\WhiteListTable */
		static public $dataClass = '\Bitrix\Security\WhiteListTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * WhiteLists
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getWhiteSubstrList()
	 * @method \string[] fillWhiteSubstr()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\WhiteList $object)
	 * @method bool has(\Bitrix\Security\WhiteList $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\WhiteList getByPrimary($primary)
	 * @method \Bitrix\Security\WhiteList[] getAll()
	 * @method bool remove(\Bitrix\Security\WhiteList $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\WhiteLists wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\WhiteList current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\WhiteLists merge(?\Bitrix\Security\WhiteLists $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_WhiteList_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\WhiteListTable */
		static public $dataClass = '\Bitrix\Security\WhiteListTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_WhiteList_Result exec()
	 * @method \Bitrix\Security\WhiteList fetchObject()
	 * @method \Bitrix\Security\WhiteLists fetchCollection()
	 */
	class EO_WhiteList_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\WhiteList fetchObject()
	 * @method \Bitrix\Security\WhiteLists fetchCollection()
	 */
	class EO_WhiteList_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\WhiteList createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\WhiteLists createCollection()
	 * @method \Bitrix\Security\WhiteList wakeUpObject($row)
	 * @method \Bitrix\Security\WhiteLists wakeUpCollection($rows)
	 */
	class EO_WhiteList_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Security\XScanResultTable:security/lib/xscanresulttable.php */
namespace Bitrix\Security {
	/**
	 * XScanResult
	 * @see \Bitrix\Security\XScanResultTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Security\XScanResult setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getType()
	 * @method \Bitrix\Security\XScanResult setType(\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \string remindActualType()
	 * @method \string requireType()
	 * @method \Bitrix\Security\XScanResult resetType()
	 * @method \Bitrix\Security\XScanResult unsetType()
	 * @method \string fillType()
	 * @method \string getSrc()
	 * @method \Bitrix\Security\XScanResult setSrc(\string|\Bitrix\Main\DB\SqlExpression $src)
	 * @method bool hasSrc()
	 * @method bool isSrcFilled()
	 * @method bool isSrcChanged()
	 * @method \string remindActualSrc()
	 * @method \string requireSrc()
	 * @method \Bitrix\Security\XScanResult resetSrc()
	 * @method \Bitrix\Security\XScanResult unsetSrc()
	 * @method \string fillSrc()
	 * @method \string getMessage()
	 * @method \Bitrix\Security\XScanResult setMessage(\string|\Bitrix\Main\DB\SqlExpression $message)
	 * @method bool hasMessage()
	 * @method bool isMessageFilled()
	 * @method bool isMessageChanged()
	 * @method \string remindActualMessage()
	 * @method \string requireMessage()
	 * @method \Bitrix\Security\XScanResult resetMessage()
	 * @method \Bitrix\Security\XScanResult unsetMessage()
	 * @method \string fillMessage()
	 * @method \float getScore()
	 * @method \Bitrix\Security\XScanResult setScore(\float|\Bitrix\Main\DB\SqlExpression $score)
	 * @method bool hasScore()
	 * @method bool isScoreFilled()
	 * @method bool isScoreChanged()
	 * @method \float remindActualScore()
	 * @method \float requireScore()
	 * @method \Bitrix\Security\XScanResult resetScore()
	 * @method \Bitrix\Security\XScanResult unsetScore()
	 * @method \float fillScore()
	 * @method \Bitrix\Main\Type\DateTime getCtime()
	 * @method \Bitrix\Security\XScanResult setCtime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $ctime)
	 * @method bool hasCtime()
	 * @method bool isCtimeFilled()
	 * @method bool isCtimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCtime()
	 * @method \Bitrix\Main\Type\DateTime requireCtime()
	 * @method \Bitrix\Security\XScanResult resetCtime()
	 * @method \Bitrix\Security\XScanResult unsetCtime()
	 * @method \Bitrix\Main\Type\DateTime fillCtime()
	 * @method \Bitrix\Main\Type\DateTime getMtime()
	 * @method \Bitrix\Security\XScanResult setMtime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $mtime)
	 * @method bool hasMtime()
	 * @method bool isMtimeFilled()
	 * @method bool isMtimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualMtime()
	 * @method \Bitrix\Main\Type\DateTime requireMtime()
	 * @method \Bitrix\Security\XScanResult resetMtime()
	 * @method \Bitrix\Security\XScanResult unsetMtime()
	 * @method \Bitrix\Main\Type\DateTime fillMtime()
	 * @method \string getTags()
	 * @method \Bitrix\Security\XScanResult setTags(\string|\Bitrix\Main\DB\SqlExpression $tags)
	 * @method bool hasTags()
	 * @method bool isTagsFilled()
	 * @method bool isTagsChanged()
	 * @method \string remindActualTags()
	 * @method \string requireTags()
	 * @method \Bitrix\Security\XScanResult resetTags()
	 * @method \Bitrix\Security\XScanResult unsetTags()
	 * @method \string fillTags()
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
	 * @method \Bitrix\Security\XScanResult set($fieldName, $value)
	 * @method \Bitrix\Security\XScanResult reset($fieldName)
	 * @method \Bitrix\Security\XScanResult unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Security\XScanResult wakeUp($data)
	 */
	class EO_XScanResult {
		/* @var \Bitrix\Security\XScanResultTable */
		static public $dataClass = '\Bitrix\Security\XScanResultTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Security {
	/**
	 * XScanResults
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getTypeList()
	 * @method \string[] fillType()
	 * @method \string[] getSrcList()
	 * @method \string[] fillSrc()
	 * @method \string[] getMessageList()
	 * @method \string[] fillMessage()
	 * @method \float[] getScoreList()
	 * @method \float[] fillScore()
	 * @method \Bitrix\Main\Type\DateTime[] getCtimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCtime()
	 * @method \Bitrix\Main\Type\DateTime[] getMtimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillMtime()
	 * @method \string[] getTagsList()
	 * @method \string[] fillTags()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Security\XScanResult $object)
	 * @method bool has(\Bitrix\Security\XScanResult $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Security\XScanResult getByPrimary($primary)
	 * @method \Bitrix\Security\XScanResult[] getAll()
	 * @method bool remove(\Bitrix\Security\XScanResult $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Security\XScanResults wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Security\XScanResult current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Security\XScanResults merge(?\Bitrix\Security\XScanResults $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_XScanResult_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Security\XScanResultTable */
		static public $dataClass = '\Bitrix\Security\XScanResultTable';
	}
}
namespace Bitrix\Security {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_XScanResult_Result exec()
	 * @method \Bitrix\Security\XScanResult fetchObject()
	 * @method \Bitrix\Security\XScanResults fetchCollection()
	 */
	class EO_XScanResult_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Security\XScanResult fetchObject()
	 * @method \Bitrix\Security\XScanResults fetchCollection()
	 */
	class EO_XScanResult_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Security\XScanResult createObject($setDefaultValues = true)
	 * @method \Bitrix\Security\XScanResults createCollection()
	 * @method \Bitrix\Security\XScanResult wakeUpObject($row)
	 * @method \Bitrix\Security\XScanResults wakeUpCollection($rows)
	 */
	class EO_XScanResult_Entity extends \Bitrix\Main\ORM\Entity {}
}