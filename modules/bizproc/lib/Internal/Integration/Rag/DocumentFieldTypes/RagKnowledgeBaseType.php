<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\DocumentFieldTypes;

use Bitrix\Bizproc\BaseType\StringType;
use Bitrix\Main\Localization\Loc;

class RagKnowledgeBaseType extends StringType
{
	public static function getName(): string
	{
		return (string)Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_NAME');
	}

	public static function getType(): string
	{
		return 'rag_knowledge_base';
	}
}