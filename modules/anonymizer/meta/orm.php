<?php

/* ORMENTITYANNOTATION:Bitrix\Anonymizer\Internal\Models\RequestTable:anonymizer/lib/Internal/Models/RequestTable.php */
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * EO_Request
	 * @see \Bitrix\Anonymizer\Internal\Models\RequestTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getQuestId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request setQuestId(\int|\Bitrix\Main\DB\SqlExpression $questId)
	 * @method bool hasQuestId()
	 * @method bool isQuestIdFilled()
	 * @method bool isQuestIdChanged()
	 * @method \int remindActualQuestId()
	 * @method \int requireQuestId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request resetQuestId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request unsetQuestId()
	 * @method \int fillQuestId()
	 * @method \string getCommand()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request setCommand(\string|\Bitrix\Main\DB\SqlExpression $command)
	 * @method bool hasCommand()
	 * @method bool isCommandFilled()
	 * @method bool isCommandChanged()
	 * @method \string remindActualCommand()
	 * @method \string requireCommand()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request resetCommand()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request unsetCommand()
	 * @method \string fillCommand()
	 * @method null|\string getHash()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request setHash(null|\string|\Bitrix\Main\DB\SqlExpression $hash)
	 * @method bool hasHash()
	 * @method bool isHashFilled()
	 * @method bool isHashChanged()
	 * @method null|\string remindActualHash()
	 * @method null|\string requireHash()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request resetHash()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request unsetHash()
	 * @method null|\string fillHash()
	 * @method null|array getResult()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request setResult(null|array|\Bitrix\Main\DB\SqlExpression $result)
	 * @method bool hasResult()
	 * @method bool isResultFilled()
	 * @method bool isResultChanged()
	 * @method null|array remindActualResult()
	 * @method null|array requireResult()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request resetResult()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request unsetResult()
	 * @method null|array fillResult()
	 * @method null|\string getError()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request setError(null|\string|\Bitrix\Main\DB\SqlExpression $error)
	 * @method bool hasError()
	 * @method bool isErrorFilled()
	 * @method bool isErrorChanged()
	 * @method null|\string remindActualError()
	 * @method null|\string requireError()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request resetError()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request unsetError()
	 * @method null|\string fillError()
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
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request set($fieldName, $value)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request reset($fieldName)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Request wakeUp($data)
	 */
	class EO_Request extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Anonymizer\Internal\Models\RequestTable */
		static public $dataClass = '\Bitrix\Anonymizer\Internal\Models\RequestTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * EO_Request_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getQuestIdList()
	 * @method \int[] fillQuestId()
	 * @method \string[] getCommandList()
	 * @method \string[] fillCommand()
	 * @method null|\string[] getHashList()
	 * @method null|\string[] fillHash()
	 * @method null|array[] getResultList()
	 * @method null|array[] fillResult()
	 * @method null|\string[] getErrorList()
	 * @method null|\string[] fillError()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Anonymizer\Internal\Models\EO_Request $object)
	 * @method bool has(\Bitrix\Anonymizer\Internal\Models\EO_Request $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request getByPrimary($primary)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request[] getAll()
	 * @method bool remove(\Bitrix\Anonymizer\Internal\Models\EO_Request $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection merge(?\Bitrix\Anonymizer\Internal\Models\EO_Request_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request|null find(callable $callback)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection filter(callable $callback)
	 */
	class EO_Request_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Anonymizer\Internal\Models\RequestTable */
		static public $dataClass = '\Bitrix\Anonymizer\Internal\Models\RequestTable';
	}
}
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Request_Result exec()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request fetchObject()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection fetchCollection()
	 */
	class EO_Request_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request fetchObject()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection fetchCollection()
	 */
	class EO_Request_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request createObject($setDefaultValues = true)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection createCollection()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request wakeUpObject($row)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Request_Collection wakeUpCollection($rows)
	 */
	class EO_Request_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Anonymizer\Internal\Models\QuestTable:anonymizer/lib/Internal/Models/QuestTable.php */
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * EO_Quest
	 * @see \Bitrix\Anonymizer\Internal\Models\QuestTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getProviderClass()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest setProviderClass(\string|\Bitrix\Main\DB\SqlExpression $providerClass)
	 * @method bool hasProviderClass()
	 * @method bool isProviderClassFilled()
	 * @method bool isProviderClassChanged()
	 * @method \string remindActualProviderClass()
	 * @method \string requireProviderClass()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest resetProviderClass()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest unsetProviderClass()
	 * @method \string fillProviderClass()
	 * @method null|array getProviderData()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest setProviderData(null|array|\Bitrix\Main\DB\SqlExpression $providerData)
	 * @method bool hasProviderData()
	 * @method bool isProviderDataFilled()
	 * @method bool isProviderDataChanged()
	 * @method null|array remindActualProviderData()
	 * @method null|array requireProviderData()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest resetProviderData()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest unsetProviderData()
	 * @method null|array fillProviderData()
	 * @method \string getHandlerClass()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest setHandlerClass(\string|\Bitrix\Main\DB\SqlExpression $handlerClass)
	 * @method bool hasHandlerClass()
	 * @method bool isHandlerClassFilled()
	 * @method bool isHandlerClassChanged()
	 * @method \string remindActualHandlerClass()
	 * @method \string requireHandlerClass()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest resetHandlerClass()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest unsetHandlerClass()
	 * @method \string fillHandlerClass()
	 * @method \string getModuleId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest resetModuleId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest unsetModuleId()
	 * @method \string fillModuleId()
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
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest set($fieldName, $value)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest reset($fieldName)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Quest wakeUp($data)
	 */
	class EO_Quest extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Anonymizer\Internal\Models\QuestTable */
		static public $dataClass = '\Bitrix\Anonymizer\Internal\Models\QuestTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * EO_Quest_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getProviderClassList()
	 * @method \string[] fillProviderClass()
	 * @method null|array[] getProviderDataList()
	 * @method null|array[] fillProviderData()
	 * @method \string[] getHandlerClassList()
	 * @method \string[] fillHandlerClass()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Anonymizer\Internal\Models\EO_Quest $object)
	 * @method bool has(\Bitrix\Anonymizer\Internal\Models\EO_Quest $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest getByPrimary($primary)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest[] getAll()
	 * @method bool remove(\Bitrix\Anonymizer\Internal\Models\EO_Quest $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection merge(?\Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest|null find(callable $callback)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection filter(callable $callback)
	 */
	class EO_Quest_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Anonymizer\Internal\Models\QuestTable */
		static public $dataClass = '\Bitrix\Anonymizer\Internal\Models\QuestTable';
	}
}
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Quest_Result exec()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest fetchObject()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection fetchCollection()
	 */
	class EO_Quest_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest fetchObject()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection fetchCollection()
	 */
	class EO_Quest_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest createObject($setDefaultValues = true)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection createCollection()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest wakeUpObject($row)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection wakeUpCollection($rows)
	 */
	class EO_Quest_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Anonymizer\Internal\Models\ReplacementsTable:anonymizer/lib/Internal/Models/ReplacementsTable.php */
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * EO_Replacements
	 * @see \Bitrix\Anonymizer\Internal\Models\ReplacementsTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getQuestId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setQuestId(\int|\Bitrix\Main\DB\SqlExpression $questId)
	 * @method bool hasQuestId()
	 * @method bool isQuestIdFilled()
	 * @method bool isQuestIdChanged()
	 * @method \int remindActualQuestId()
	 * @method \int requireQuestId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements resetQuestId()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unsetQuestId()
	 * @method \int fillQuestId()
	 * @method \string getCode()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements resetCode()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unsetCode()
	 * @method \string fillCode()
	 * @method \string getText()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setText(\string|\Bitrix\Main\DB\SqlExpression $text)
	 * @method bool hasText()
	 * @method bool isTextFilled()
	 * @method bool isTextChanged()
	 * @method \string remindActualText()
	 * @method \string requireText()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements resetText()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unsetText()
	 * @method \string fillText()
	 * @method \string getLabel()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setLabel(\string|\Bitrix\Main\DB\SqlExpression $label)
	 * @method bool hasLabel()
	 * @method bool isLabelFilled()
	 * @method bool isLabelChanged()
	 * @method \string remindActualLabel()
	 * @method \string requireLabel()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements resetLabel()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unsetLabel()
	 * @method \string fillLabel()
	 * @method \int getStart()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setStart(\int|\Bitrix\Main\DB\SqlExpression $start)
	 * @method bool hasStart()
	 * @method bool isStartFilled()
	 * @method bool isStartChanged()
	 * @method \int remindActualStart()
	 * @method \int requireStart()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements resetStart()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unsetStart()
	 * @method \int fillStart()
	 * @method \int getEnd()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements setEnd(\int|\Bitrix\Main\DB\SqlExpression $end)
	 * @method bool hasEnd()
	 * @method bool isEndFilled()
	 * @method bool isEndChanged()
	 * @method \int remindActualEnd()
	 * @method \int requireEnd()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements resetEnd()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unsetEnd()
	 * @method \int fillEnd()
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
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements set($fieldName, $value)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements reset($fieldName)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Replacements wakeUp($data)
	 */
	class EO_Replacements extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Anonymizer\Internal\Models\ReplacementsTable */
		static public $dataClass = '\Bitrix\Anonymizer\Internal\Models\ReplacementsTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * EO_Replacements_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getQuestIdList()
	 * @method \int[] fillQuestId()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getTextList()
	 * @method \string[] fillText()
	 * @method \string[] getLabelList()
	 * @method \string[] fillLabel()
	 * @method \int[] getStartList()
	 * @method \int[] fillStart()
	 * @method \int[] getEndList()
	 * @method \int[] fillEnd()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Anonymizer\Internal\Models\EO_Replacements $object)
	 * @method bool has(\Bitrix\Anonymizer\Internal\Models\EO_Replacements $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements getByPrimary($primary)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements[] getAll()
	 * @method bool remove(\Bitrix\Anonymizer\Internal\Models\EO_Replacements $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection merge(?\Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements|null find(callable $callback)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection filter(callable $callback)
	 */
	class EO_Replacements_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Anonymizer\Internal\Models\ReplacementsTable */
		static public $dataClass = '\Bitrix\Anonymizer\Internal\Models\ReplacementsTable';
	}
}
namespace Bitrix\Anonymizer\Internal\Models {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Replacements_Result exec()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements fetchObject()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection fetchCollection()
	 */
	class EO_Replacements_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements fetchObject()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection fetchCollection()
	 */
	class EO_Replacements_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements createObject($setDefaultValues = true)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection createCollection()
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements wakeUpObject($row)
	 * @method \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection wakeUpCollection($rows)
	 */
	class EO_Replacements_Entity extends \Bitrix\Main\ORM\Entity {}
}