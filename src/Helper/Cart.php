<?php

/**
 * Copyright Shopgate Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Shopgate Inc, 804 Congress Ave, Austin, Texas 78701 <interfaces@shopgate.com>
 * @copyright Shopgate Inc
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

namespace Shopgate\Bolt\Helper;

use Shopgate\Export\Helper\Cart as OriginalCartHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Shopgate\Base\Model\Config;
use Shopgate\Base\Model\Utility\SgLoggerInterface;
use Shopgate\Export\Helper\Quote as QuoteHelper;
use Magento\Framework\Serialize\SerializerInterface;
use Shopgate\Bolt\Model\Shopgate\ShopgateQuoteFlag;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;

class Cart extends OriginalCartHelper
{
    /** @var Config */
    private $config;
    /** @var SgLoggerInterface */
    private $logger;
    /** @var QuoteHelper */
    private $quoteHelper;
    /** @var array */
    private $quoteFields;
    /** @var RequestInterface */
    protected $request;
    /** @var bool */
    protected $isBoltRequest;
    /** @var SerializerInterface */
    private $serializer;
    /** @var ShopgateQuoteFlag */
    private $quoteFlag;
    /** @var Context */
    private $context;
    /** @var Registry */
    private $registry;

    /**
     * @param Config              $config
     * @param SgLoggerInterface   $logger
     * @param QuoteHelper         $quoteHelper
     * @param RequestInterface    $request
     * @param SerializerInterface $serializer
     * @param ShopgateQuoteFlag   $quoteFlag,
     * @param Context             $context,
     * @param Registry            $registry,
     * @param array               $quoteFields
     * @param array               $quoteStockFields
     */
    public function __construct(
        Config $config,
        SgLoggerInterface $logger,
        QuoteHelper $quoteHelper,
        RequestInterface $request,
        SerializerInterface $serializer,
        ShopgateQuoteFlag $quoteFlag,
        Context $context,
        Registry $registry,
        array $quoteFields = [],
        array $quoteStockFields = []
    ) {
        $this->config        = $config;
        $this->logger        = $logger;
        $this->quoteHelper   = $quoteHelper;
        $this->quoteFields   = $quoteFields;
        $this->request       = $request;
        $this->serializer    = $serializer;
        $this->isBoltRequest = $this->getBoltRequestFlag($request);
        $this->quoteFlag     = $quoteFlag;
        $this->context       = $context;
        $this->registry      = $registry;
        parent::__construct($config, $logger, $quoteHelper, $quoteFields, $quoteStockFields);
    }

    /**
     * Takes in the allowed cart methods loaded up in the Base's DI
     *
     * @return array
     * @throws \Exception
     */
    public function loadSupportedMethods()
    {
        $this->quoteHelper->load($this->quoteFields);
        $fields = $this->loadMethods($this->config->getSupportedFieldsCheckCart());
        if ($this->isBoltRequest) {
            $this->quoteHelper->addBoltParentId();
            $this->setShopgateQuoteFlag();
        }
        if (!$this->isBoltRequest) {
            $this->quoteHelper->cleanup();
        }

        return $fields;
    }

    /**
     * Loads the methods of the current class
     *
     * @param array $fields
     *
     * @return array
     */
    private function loadMethods(array $fields)
    {
        $methods = [];
        foreach ($fields as $rawField) {
            $method = 'get' . SimpleDataObjectConverter::snakeCaseToUpperCamelCase($rawField);
            $this->logger->debug('Starting method ' . $method);
            $methods[$rawField] = $this->{$method}();
            $this->logger->debug('Finished method ' . $method);
        }

        return $methods;
    }

    /**
     * @return mixed
     */
    protected function getInternalCartInfo()
    {
        if ($this->isBoltRequest) {
            return $this->serializer->serialize([
                'quote_id' => $this->quoteHelper->getCurrentQuoteId(),
                'reserved_order_id' => $this->quoteHelper->getReservedOrderId()
            ]);
        }
        return '{}';
    }

    private function setShopgateQuoteFlag()
    {
        $shopgateQuoteFlag = new $this->quoteFlag($this->context, $this->registry);
        $shopgateQuoteFlag->setQuoteId($this->quoteHelper->getCurrentQuoteId());
        $shopgateQuoteFlag->setIsShopgateOrder(true);
    }

    private function getBoltRequestFlag(RequestInterface $request)
    {
        $requestCart = $request->getParam('cart');

        if (empty($requestCart) || empty($requestCart['internal_cart_info'])) {
            return false;
        }

        $internalCartInfo = $this->serializer->unserialize($requestCart['internal_cart_info']);

        return !empty($internalCartInfo['bolt_request']) ? $internalCartInfo['bolt_request'] : false;
    }
}
