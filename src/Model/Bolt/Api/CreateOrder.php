<?php


namespace Shopgate\Bolt\Model\Bolt\Api;

use Bolt\Boltpay\Model\Api\CreateOrder as originalCreateOrder;
use Bolt\Boltpay\Api\CreateOrderInterface;
use Bolt\Boltpay\Exception\BoltException;
use Bolt\Boltpay\Helper\Cart;
use Magento\Framework\Exception\LocalizedException;
use Bolt\Boltpay\Helper\Order as OrderHelper;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Log as LogHelper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Bolt\Boltpay\Helper\Hook as HookHelper;
use Bolt\Boltpay\Helper\Bugsnag;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Framework\UrlInterface;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\CatalogInventory\Helper\Data as CatalogInventoryData;
use Bolt\Boltpay\Helper\Session as SessionHelper;
use Shopgate\Bolt\Helper\ShopgateQuoteFlag;

class CreateOrder extends originalCreateOrder
{
    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var LogHelper
     */
    private $logHelper;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Bugsnag
     */
    private $bugsnag;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var CartHelper
     */
    private $cartHelper;

    /**
     * @var ShopgateQuoteFlag
     */
    private $shopgateQuoteFlagHelper;

    /**
     * @param HookHelper               $hookHelper
     * @param OrderHelper              $orderHelper
     * @param CartHelper               $cartHelper
     * @param LogHelper                $logHelper
     * @param Request                  $request
     * @param Bugsnag                  $bugsnag
     * @param Response                 $response
     * @param UrlInterface             $url
     * @param BackendUrl               $backendUrl
     * @param ConfigHelper             $configHelper
     * @param StockRegistryInterface   $stockRegistry
     * @param SessionHelper            $sessionHelper
     * @param ShopgateQuoteFlag        $shopgateQuoteFlagHelper
     */
    public function __construct(
        HookHelper $hookHelper,
        OrderHelper $orderHelper,
        CartHelper $cartHelper,
        LogHelper $logHelper,
        Request $request,
        Bugsnag $bugsnag,
        Response $response,
        UrlInterface $url,
        BackendUrl $backendUrl,
        ConfigHelper $configHelper,
        StockRegistryInterface $stockRegistry,
        SessionHelper $sessionHelper,
        ShopgateQuoteFlag $shopgateQuoteFlagHelper
    ) {
        $this->orderHelper = $orderHelper;
        $this->logHelper = $logHelper;
        $this->request = $request;
        $this->bugsnag = $bugsnag;
        $this->response = $response;
        $this->cartHelper = $cartHelper;
        $this->shopgateQuoteFlagHelper = $shopgateQuoteFlagHelper;
        parent::__construct($hookHelper, $orderHelper, $cartHelper, $logHelper, $request, $bugsnag, $response, $url, $backendUrl, $configHelper, $stockRegistry, $sessionHelper);
    }

    /**
     * Pre-Auth hook: Create order.
     *
     * @api
     * @inheritDoc
     * @see CreateOrderInterface
     */
    public function execute(
        $type = null,
        $order = null,
        $currency = null
    ) {
        try {

            $payload = $this->request->getContent();
            $this->logHelper->addInfoLog('[-= Pre-Auth CreateOrder =-]');
            $this->logHelper->addInfoLog($payload);

            if ($type !== 'order.create') {
                throw new BoltException(
                    __('Invalid hook type!'),
                    null,
                    self::E_BOLT_GENERAL_ERROR
                );
            }

            if (empty($order)) {
                throw new BoltException(
                    __('Missing order data.'),
                    null,
                    self::E_BOLT_GENERAL_ERROR
                );
            }

            $quoteId = $this->getQuoteIdFromPayloadOrder($order);
            /** @var Quote $immutableQuote */
            $immutableQuote = $this->loadQuoteData($quoteId);

            $this->preProcessWebhook($immutableQuote->getStoreId());

            $transaction = json_decode($payload);

            /** @var Quote $quote */
            $quote = $this->orderHelper->prepareQuote($immutableQuote, $transaction);

            /** @var \Magento\Sales\Model\Order $createdOrder */
            $createdOrder = $this->orderHelper->processExistingOrder($quote, $transaction);

            if (! $createdOrder) {
                $this->validateQuoteData($quote, $transaction);
                $createdOrder = $this->orderHelper->processNewOrder($quote, $transaction);
            }
            $isShopgateOrder = $this->shopgateQuoteFlagHelper->isShopgateQuote($quoteId);
            $responseData = [
                'status'    => 'success',
                'message'   => 'Order create was successful',
                'display_id' => $createdOrder->getIncrementId() . ' / ' . $quote->getId(),
                'total'      => $this->cartHelper->getRoundAmount($createdOrder->getGrandTotal()),
            ];

            if (!$isShopgateOrder) {
                $responseData['order_received_url'] = $this->getReceivedUrl($immutableQuote);
            }

            $this->sendResponse(200, $responseData);
        } catch (\Magento\Framework\Webapi\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendResponse($e->getHttpCode(), [
                'status' => 'failure',
                'error'  => [[
                    'code' => self::E_BOLT_GENERAL_ERROR,
                    'data' => [[
                        'reason' => $e->getCode() . ': ' . $e->getMessage(),
                    ]]
                ]]
            ]);
        } catch (BoltException $e) {
            $this->bugsnag->notifyException($e);
            $this->sendResponse(422, [
                'status' => 'failure',
                'error'  => [[
                    'code' => $e->getCode(),
                    'data' => [[
                        'reason' => $e->getMessage(),
                    ]]
                ]]
            ]);
        } catch (LocalizedException $e) {
            $this->bugsnag->notifyException($e);
            $this->sendResponse(422, [
                'status' => 'failure',
                'error' => [[
                    'code' => 6009,
                    'data' => [[
                        'reason' => 'Unprocessable Entity: ' . $e->getMessage(),
                    ]]
                ]]
            ]);
        } catch (\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendResponse(422, [
                'status' => 'failure',
                'error' => [[
                    'code' => self::E_BOLT_GENERAL_ERROR,
                    'data' => [[
                        'reason' => $e->getMessage(),
                    ]]
                ]]
            ]);
        } finally {
            $this->response->sendResponse();
        }
    }
}
