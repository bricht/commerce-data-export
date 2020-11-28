<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\CatalogPriceDataExporter\Model\Event;

use Magento\CatalogPriceDataExporter\Model\EventBuilder;
use Magento\CatalogPriceDataExporter\Model\Query\ProductPrice;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ProductPriceEvent implements ProductPriceEventInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ProductPrice
     */
    private $productPrice;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var EventBuilder
     */
    private $eventBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ProductPrice $productPrice
     * @param StoreManagerInterface $storeManager
     * @param EventBuilder $eventBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductPrice $productPrice,
        StoreManagerInterface $storeManager,
        EventBuilder $eventBuilder,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productPrice = $productPrice;
        $this->storeManager = $storeManager;
        $this->eventBuilder = $eventBuilder;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function retrieve(array $data): array
    {
        $result = [];
        $events = [];

        try {
            $attributes = \array_unique(\explode(',', $data['attributes']));
            $select = $this->productPrice->getQuery($data['entity_id'], $data['scope_id'], $attributes);
            $cursor = $this->resourceConnection->getConnection()->query($select);

            while ($row = $cursor->fetch()) {
                $result[$row['attribute_code']] = $row;
            }

            foreach ($attributes as $attributeCode) {
                $events[] = $this->getEventData($attributeCode, $data, $result[$attributeCode]['value'] ?? null);
            }
        } catch (\Throwable $exception) {
            // TODO log error, throw exception
            $this->logger->error('Error retrieving product price data.', ['exception' => $exception->getMessage()]);
        }

        return $events;
    }

    /**
     * Retrieve event data.
     *
     * @param string $attributeCode
     * @param array $data
     * @param string|null $attributeValue
     *
     * @return array
     *
     * @throws NoSuchEntityException
     */
    private function getEventData(string $attributeCode, array $data, ?string $attributeValue): array
    {
        $scopeCode = $this->storeManager->getStore($data['scope_id'])->getWebsite()->getCode();
        $eventType = null === $attributeValue ? self::EVENT_PRICE_DELETED : self::EVENT_PRICE_CHANGED;

        return $this->eventBuilder->build(
            $eventType,
            $data['entity_id'],
            $scopeCode,
            null,
            $attributeValue,
            ['meta' => ['code' => $attributeCode]]
        );
    }
}