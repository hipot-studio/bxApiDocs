<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

final class DatasetFieldAssembler extends EntityLinkFieldAssembler
{
	protected function getEntityType(): string
	{
		return 'dataset';
	}

	protected function getNameKey(): string
	{
		return 'EXTERNAL_DATASET_NAME';
	}

	protected function getIdKey(): string
	{
		return 'EXTERNAL_DATASET_ID';
	}
}
