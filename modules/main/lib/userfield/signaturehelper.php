<?php
namespace Bitrix\Main\UserField;

class SignatureHelper
{
	public static function getSignature(SignatureManager $signatureManager, array $fieldParam)
	{
		return $signatureManager->getSignature(
			static::getSignatureParam($fieldParam)
		);
	}

	public static function validateSignature(SignatureManager $signatureManager, array $fieldParam, $signature)
	{
		return $signatureManager->validateSignature(
			static::getSignatureParam($fieldParam),
			$signature
		);
	}

	protected static function getSignatureParam(array $fieldParam)
	{
		$signatureParam = array(
			'ENTITY_ID' => $fieldParam['ENTITY_ID'],
			'FIELD' => $fieldParam['FIELD'],
		);

		if(!empty($fieldParam['VALUE']))
		{
			$signatureParam['VALUE'] = $fieldParam['VALUE'];
		}

		if(!empty($fieldParam['CONTEXT']))
		{
			$signatureParam['CONTEXT'] = $fieldParam['CONTEXT'];
		}

		array_walk_recursive($signatureParam, function(&$item)
		{
			$item = strval($item);
		});

		if(isset($signatureParam['VALUE']))
		{
			$signatureParam['VALUE'] = str_replace("\r\n", "\n", $signatureParam['VALUE']);
		}

		return serialize($signatureParam);
	}
}
