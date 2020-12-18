<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\CatalogPriceDataExporter\Model\Provider\PartialReindex;

use Magento\CatalogDataExporter\Model\Provider\Product\ProductOptions\CustomizableSelectedOptionValueUid;
use Magento\CatalogPriceDataExporter\Model\EventKeyGenerator;
use Magento\CatalogPriceDataExporter\Model\Query\CustomOptionTypePrice;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class responsible for providing custom selectable option price events
 */
class CustomOptionTypePriceEvent implements PartialReindexPriceProviderInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var CustomOptionTypePrice
     */
    private $customOptionTypePrice;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CustomizableSelectedOptionValueUid
     */
    private $optionValueUid;

    /**
     * @var EventKeyGenerator
     */
    private $eventKeyGenerator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ResourceConnection $resourceConnection
     * @param CustomOptionTypePrice $customOptionTypePrice
     * @param StoreManagerInterface $storeManager
     * @param CustomizableSelectedOptionValueUid $optionValueUid
     * @param EventKeyGenerator $eventKeyGenerator
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        CustomOptionTypePrice $customOptionTypePrice,
        StoreManagerInterface $storeManager,
        CustomizableSelectedOptionValueUid $optionValueUid,
        EventKeyGenerator $eventKeyGenerator,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->customOptionTypePrice = $customOptionTypePrice;
        $this->storeManager = $storeManager;
        $this->optionValueUid = $optionValueUid;
        $this->eventKeyGenerator = $eventKeyGenerator;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function retrieve(array $indexData): \Generator
    {
        try {
            foreach (\array_chunk($indexData, self::BATCH_SIZE) as $indexDataChunk) {
                $result = [];
                $queryArguments = $this->buildQueryArguments($indexDataChunk);
                foreach ($queryArguments as $scopeId => $optionTypeIds) {
                    $select = $this->customOptionTypePrice->getQuery($optionTypeIds, $scopeId);
                    $cursor = $this->resourceConnection->getConnection()->query($select);
                    while ($row = $cursor->fetch()) {
                        $result[$scopeId][$row['option_type_id']] = [
                            'option_id' => $row['option_id'],
                            'option_type_id' => $row['option_type_id'],
                            'price' => $row['price'],
                            'price_type' => $row['price_type'],
                        ];
                    }
                }
                yield $this->getEventsData($result);
            }
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage());
            throw new UnableRetrieveData('Unable to retrieve product custom option types price data.');
        }
    }

    /**
     * Build query arguments from index data or no data in case of full sync
     *
     * @param array $indexData
     *
     * @return array
     */
    private function buildQueryArguments(array $indexData): array
    {
        $queryArguments = [];
        foreach ($indexData as $data) {
            $queryArguments[$data['scope_id']][] = $data['entity_id'];
        }
        return $queryArguments;
    }

    /**
     * Retrieve prices event data
     *
     * @param array $resultData
     *
     * @return array
     *
     * @throws NoSuchEntityException
     * @throws \InvalidArgumentException
     */
    private function getEventsData(array $resultData): array
    {
        $events = [];

        foreach ($resultData as $scopeId => $data) {
            foreach ($data as $priceData) {
                $websiteId = (string)$this->storeManager->getStore($scopeId)->getWebsiteId();
                $key = $this->eventKeyGenerator->generate(
                    self::EVENT_CUSTOM_OPTION_TYPE_PRICE_CHANGED,
                    $websiteId,
                    null
                );
                $events[$key][] = $this->buildEventData($priceData);
            }
        }

        return $events;
    }

    /**
     * Build event data
     *
     * @param array $data
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    private function buildEventData(array $data): array
    {
        $id = $this->optionValueUid->resolve(
            [
                CustomizableSelectedOptionValueUid::OPTION_ID => $data['option_id'],
                CustomizableSelectedOptionValueUid::OPTION_VALUE_ID => $data['option_type_id'],
            ]
        );

        return [
            'id' => $id,
            'value' => $data['price'],
            'price_type' => $data['price_type'],
        ];
    }
}