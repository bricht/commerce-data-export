<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\CatalogPriceDataExporter\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

class ProductPrice
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Retrieve query for product price.
     *
     * @param string $entityId
     * @param string $scopeId
     * @param array $attributes
     *
     * @return Select
     */
    public function getQuery(string $entityId, string $scopeId, array $attributes): Select
    {
        $connection = $this->resourceConnection->getConnection();
        $productEntityTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $joinField = $connection->getAutoIncrementField($productEntityTable);

        return $connection->select()
            ->from(['cpe' => $productEntityTable], [])
            ->join(
                ['cped' => $this->resourceConnection->getTableName(['catalog_product_entity', 'decimal'])],
                \sprintf('cpe.%1$s = cped.%1$s', $joinField),
                []
            )
            ->join(
                ['eav' => $this->resourceConnection->getTableName('eav_attribute')],
                'cped.attribute_id = eav.attribute_id',
                []
            )
            ->columns(
                [
                    'attribute_code' => 'eav.attribute_code',
                    'value' => 'cped.value',
                ]
            )
            ->where('eav.attribute_code IN (?)', $attributes)
            ->where('cped.store_id = ?', $scopeId)
            ->where('cpe.entity_id = ?', $entityId);
    }
}