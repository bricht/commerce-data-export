<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProductDataExporter\Plugin;

use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ResourceModel\Product as ResourceProduct;
use Magento\ConfigurableProductDataExporter\Model\Query\LinkedAttributesQuery;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\AbstractModel;
use Magento\ProductVariantDataExporter\Model\Indexer\ProductVariantFeedIndexer;
use Magento\ProductVariantDataExporter\Model\Indexer\UpdateChangeLog;

/**
 * Plugin to trigger reindex on parent products, when a super attribute value is changed on a child product
 */
class ReindexVariantsAfterSave
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var LinkedAttributesQuery
     */
    private $linkedAttributesQuery;

    /**
     * @var IndexerRegistry
     */
    private $indexerRegistry;

    /**
     * @var UpdateChangeLog
     */
    private $updateChangeLog;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LinkedAttributesQuery $linkedAttributesQuery
     * @param IndexerRegistry $indexerRegistry
     * @param UpdateChangeLog $updateChangeLog
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        LinkedAttributesQuery $linkedAttributesQuery,
        IndexerRegistry $indexerRegistry,
        UpdateChangeLog $updateChangeLog
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->linkedAttributesQuery = $linkedAttributesQuery;
        $this->indexerRegistry = $indexerRegistry;
        $this->updateChangeLog = $updateChangeLog;
    }

    /**
     * Reindex parent products on change of child product attribute value
     *
     * @param ResourceProduct $subject
     * @param ResourceProduct $result
     * @param AbstractModel $product
     * @return ResourceProduct
     * @throws \Zend_Db_Statement_Exception
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(
        ResourceProduct $subject,
        ResourceProduct $result,
        AbstractModel $product
    ): ResourceProduct {
        if (\in_array($product->getTypeId(), [Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL], true)) {
            $select = $this->linkedAttributesQuery->getQuery((int)$product->getId());
            $connection = $this->resourceConnection->getConnection();
            $configurableLinks = $connection->query($select)->fetchAll();
            $changedConfigurableIds = [];
            foreach ($configurableLinks as $link) {
                if ($product->getOrigData($link['attributeCode']) !== $product->getData($link['attributeCode'])) {
                    $changedConfigurableIds[] = $link['parentId'];
                }
            }
            if (!empty($changedConfigurableIds)) {
                $this->reindexVariants($changedConfigurableIds);
            }
        }
        return $result;
    }

    /**
     * Reindex product variants
     *
     * @param int[] $ids
     * @return void
     */
    private function reindexVariants(array $ids): void
    {
        $indexer = $this->indexerRegistry->get(ProductVariantFeedIndexer::INDEXER_ID);

        if ($indexer->isScheduled()) {
            $this->updateChangeLog->execute($indexer->getViewId(), $ids);
        } else {
            $indexer->reindexList($ids);
        }
    }
}