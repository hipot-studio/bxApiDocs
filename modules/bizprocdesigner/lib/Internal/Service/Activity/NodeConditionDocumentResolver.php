<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Document type the condition of a node is decided on, by the raw values of the form of its general settings
 * - the answer the settings panel re-asks for while its user edits that form.
 *
 * Nothing is converted and nothing is validated here. The values are read one property at a time, by the very
 * call the save path reads them with, and only under the names the class of the node declares as the sources
 * of its condition document
 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getConditionDocumentSourceFieldNames()}).
 * A full conversion was rejected deliberately: for a node whose form names documents it assembles the
 * `Options` of every one of them and ends in validation, and dropping only its last step would leave a second,
 * non-validating conversion beside the saving one.
 *
 * The class is a unit of its own, and not a private method of the controller, so that the answer can be
 * proved without a request.
 */
final class NodeConditionDocumentResolver
{
	use ActivitySettingsDecoder;

	/**
	 * @param string $activityType Type of the node, the single source of the type for the resolution.
	 * @param array $formData Raw values of the form of the general settings of the node, as the panel holds
	 *     them - the same payload the save of a rule is sent. A nested `documentType` is honoured the way the
	 *     save path honours it; everything else is read only through the properties map of the node.
	 * @param array $documentType Document type of the edited template: what the node is asked in while its
	 *     form names no document of its own.
	 * @return Result data on success: [
	 *     'conditionDocumentType' => ?array - the complex type, null while the condition of the node is
	 *         decided on the document of the template (a legal answer, not a refusal),
	 *     'configurator' => Configurator - built here once, so the caller gating the settings surface of the
	 *         node does not pay for a second properties map,
	 *   ]; ACTIVITY_NOT_FOUND for a node the mechanism does not serve (no type, no declared source names, no
	 *   properties map of its own), EMPTY_PROP when no declared name is present in the raw values at all, and
	 *   ACCESS_DENIED for a nested document type that names no registered document.
	 */
	public function resolveByFormData(string $activityType, array $formData, array $documentType): Result
	{
		Loader::requireModule('bizproc');

		$result = new Result();
		$complexActivityService = BizprocContainer::instance()->getComplexActivityService();

		// The refused node is named in the message, and only while its type is an activity code - the same
		// character set \CBPActivity::createInstance() accepts - so a type a client made up is reported
		// without its own text reaching the message.
		$activityNameReplace = preg_match('#\A[a-zA-Z0-9_]+\z#', $activityType) === 1
			? ['#ACTIVITY#' => $activityType]
			: []
		;

		// The cheapest guard first: a node that names no source of its condition document has nothing to be
		// asked about, and the panel of such a node never asks.
		$sourceFieldNames = $complexActivityService->getConditionDocumentSourceFieldNames($activityType);
		if ($sourceFieldNames === [])
		{
			return $result->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getCodedError($activityNameReplace));
		}

		$documentTypeResult = $this->extractDocumentType($formData);
		if (!$documentTypeResult->isSuccess())
		{
			return $documentTypeResult;
		}
		$nodeDocumentType = $documentTypeResult->getData()['documentType'] ?? $documentType;

		try
		{
			['properties' => $rawValues] = $this->decodeActivitySettings($formData, $nodeDocumentType);
			$rawValues = is_array($rawValues) ? $rawValues : [];

			// The document type must reach getPropertiesMap(): the metadata of a field that depends on it
			// builds empty otherwise and the submitted value is then dropped. Same call the save path makes.
			// The context is the one the settings controls endpoint publishes: without it the node builds the
			// settings of a condition property this request never reads - the whole field list of its entity.
			$configurator = \CBPActivity::createConfigurator(
				$activityType,
				$rawValues,
				$nodeDocumentType,
				[
					'ConditionBlockAvailable' => UnifiedPanelSurfaceAvailability::isConditionBlockAvailableForNode(
						$activityType,
						$complexActivityService->getActivityDescriptionByCode(strtolower($activityType)),
					),
				],
			);
			$propertiesMap = $configurator->getPropertiesMap();
		}
		catch (\Throwable)
		{
			// Read-only request of a panel: a node whose own module cannot describe its form answers "not
			// served" instead of breaking the editing of the template.
			return $result->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getCodedError($activityNameReplace));
		}

		if ($propertiesMap === [])
		{
			// The node draws a legacy dialog of its own, so there is no map to read its declared names off.
			return $result->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getCodedError($activityNameReplace));
		}

		$properties = $this->extractDeclaredValues(
			$propertiesMap,
			$sourceFieldNames,
			$rawValues,
			$nodeDocumentType,
		);
		if ($properties === [])
		{
			// Not "the type is unknown": the server does not take the word of a client that sent none of the
			// fields the answer is made of.
			return $result->addError(
				ErrorMessage::EMPTY_PROP->getCodedError(['#PROPERTY#' => implode(', ', $sourceFieldNames)]),
			);
		}

		return $result->setData([
			'conditionDocumentType' => $complexActivityService->getConditionDocumentTypeForNode(
				$activityType,
				$properties,
			),
			'configurator' => $configurator,
		]);
	}

	/**
	 * Values of the declared source properties, keyed by their own keys in the properties map - the shape
	 * `initializeFromArray()` reads a node back from.
	 *
	 * A property is taken only while the raw values actually carry it, under its own name or under the
	 * companion `_text` one the extraction of a selector value reads
	 * ({@see \Bitrix\Bizproc\BaseType\Base::extractValueSingle()}). An empty value of a present property is
	 * kept: clearing the document of a node is exactly how its condition returns to the document of the
	 * template, and answering that with a refusal would leave the panel on the stale type.
	 */
	private function extractDeclaredValues(
		array $propertiesMap,
		array $sourceFieldNames,
		array $rawValues,
		array $documentType,
	): array
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$properties = [];
		// Required by reference and deliberately not consumed: nothing is stored on this path, and a field
		// left unfilled is the business of the save path.
		$errors = [];

		foreach ($propertiesMap as $propertyKey => $fieldProperties)
		{
			$fieldName = (string)($fieldProperties['FieldName'] ?? $propertyKey);
			if (
				!in_array($fieldName, $sourceFieldNames, true)
				|| !(
					array_key_exists($fieldName, $rawValues)
					|| array_key_exists($fieldName . '_text', $rawValues)
				)
			)
			{
				continue;
			}

			$properties[$propertyKey] = $documentService->getFieldInputValue(
				$documentType,
				$fieldProperties,
				['Field' => $fieldName],
				$rawValues,
				$errors,
			);
		}

		return $properties;
	}
}
