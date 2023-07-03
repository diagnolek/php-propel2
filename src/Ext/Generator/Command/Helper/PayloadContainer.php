<?php

namespace Propel\Ext\Generator\Command\Helper;

use Propel\Runtime\ActiveQuery\ModelCriteria;

class PayloadContainer
{
    private ModelCriteria $queryPropel;

    private array $data;

    public function __construct(ModelCriteria $queryPropel, array $data)
    {
        $this->queryPropel = $queryPropel;
        $this->data = $data;
    }

    public function getQueryPropel(): ModelCriteria
    {
        return $this->queryPropel;
    }

    public function getData(): array
    {
        return $this->data;
    }

}
