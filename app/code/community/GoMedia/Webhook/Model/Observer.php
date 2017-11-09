<?php

/**
 * Observer to handle event
 * Sends JSON data to URL specified in extensions admin settings
 *
 * @author Chris Sohn (www.gomedia.co.za)
 * @copyright  Copyright (c) 2015 Go Media
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 */
class GoMedia_Webhook_Model_Observer {

    /**
     * Used to ensure the event is not fired multiple times
     * http://magento.stackexchange.com/questions/7730/sales-order-save-commit-after-event-triggered-twice
     *
     * @var bool
     */
    private $_orderStatus = '';

    /**
     * Posts order
     *
     * @param Varien_Event_Observer $observer
     * @return GoMedia_Webhook_Model_Observer
     */
    public function postWebhook($observer) {

        /** @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getOrder();
        $orderStatus = $order->getStatus();
        $orderState = $order->getState();
        $url = Mage::getStoreConfig('webhook/order/url', $order['store_id']);

        // make sure this has not already run and status has changed
        // each time the order is saved this observer is called.
        // doing this avoids endless loop since we save the order here to write comments
        if (!is_null($orderStatus) && $url && $this->_orderStatus != $orderStatus) {
            try {
                $data = $this->transformOrder($order);
                $response = $this->proxy($data, $url);
            } catch(Exception $e) {
                $response = new stdClass();
                $response->body = $e->getMessage();
            }

            // save comment
            $output = 'GoMedia Web Hook: Sent, Status: ' . $orderStatus . " State: " . $orderState . " Response: " . (string)$response->body;
            $output = substr($output, 0 , 25000);
            $order->addStatusHistoryComment($output, false);
            $this->_orderStatus = $orderStatus;
            $order->save();
        }
        return $this;
    }


    /**
     * Curl data and return body
     *
     * @param $data
     * @param $url
     * @return stdClass $output
     */
    private function proxy($data, $url) {

        $output = new stdClass();
        $ch = curl_init();
        $body = json_encode($data);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            //'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            // http://stackoverflow.com/questions/11359276/php-curl-exec-returns-both-http-1-1-100-continue-and-http-1-1-200-ok-separated-b
            'Expect:' // Remove "HTTP/1.1 100 Continue" from response
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60 * 2); // 2 minutes to connect
        curl_setopt($ch, CURLOPT_TIMEOUT, 60 * 4); // 8 minutes to fetch the response
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // ignore cert issues
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // execute
        $response = curl_exec($ch);
        $output->status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // handle response
        $arr = explode("\r\n\r\n", $response, 2);
        if (count($arr) == 2) {
            $output->header = $arr[0];
            $output->body = $arr[1];
        } else {
            $output->body = "Unexpected response";
        }
        return $output;
    }

    /**
     * Transform order into one data object for posting
     */
    /**
     * @param $orderIn Mage_Sales_Model_Order
     * @return mixed
     */
    private function transformOrder($orderIn) {
        $orderOut = $orderIn->getData();
        $orderOut['line_items'] = array();
        $items = $orderIn->getAllItems();
        $itemCount = 0;

        /** @var $item Mage_Sales_Model_Order_Item */
        foreach ($items as $item) {
            $itemData = $item->getData();

            // get bundles "base" price
            // TODO this only works for fixed bundle pricing
            if($itemData['product_type'] == 'bundle') {
                $bundlePrice = $item->getPrice();
                $bundleCount = 0;
                $bundleIndex = $itemCount;
                $children = $item->getChildrenItems();
                $bundleCountTotal = count($children);
            }

            // change price values for item if bundled or configurable
            if ($itemData['product_type'] == 'simple') {

                // we only process simple products
                // do we have a parent item
                $parentItemId = $item->getParentItemId();

                /** @var $parentItem Mage_Sales_Model_Order_Item */
                $parentItem = Mage::getModel('sales/order_item')->load("$parentItemId");
                $parentPrice = $parentItem->getPrice();
                $parentQty = $parentItem->getQtyOrdered();
                $parentTax = $parentItem->getTaxAmount();
                $parentPercent = $parentItem->getTaxPercent();

                switch($parentItem->getProductType()) {
                    case "configurable":
                        if(!is_null($parentItemId)) {
                            // make sure item has price, otherwise use parent price
                            if($itemData['price'] == "0.0000") {
                                $itemData['price'] = $parentPrice;
                            }
                            if($itemData['qty_ordered'] == "0") {
                                $itemData['qty_ordered'] = $parentQty;
                            }
                            if($itemData['tax_amount'] == "0.0000") {
                                $itemData['tax_amount'] = $parentTax;
                            }
                            if($itemData['tax_percent'] == "0.0000") {
                                $itemData['tax_percent'] = $parentPercent;
                            }
                        }
                        break;
                    case "bundle";
                        $bundleCount++;
                        $unserialise = unserialize($itemData['product_options']);
                        $selection = unserialize($unserialise['bundle_selection_attributes']);
                        if(isset($selection['price'])) {
                            $itemData['price'] = $selection['price'];
                            $bundlePrice -= $itemData['price'];

                            // this is the last product on the bundle,
                            // set the remaining amount to the first product
                            if($bundleCount == $bundleCountTotal && $bundlePrice > 0) {
                                if(!isset($orderOut['line_items'][$bundleIndex])) {
                                    // this bundle has only one option, so use price.
                                    $itemData['price'] = $bundlePrice;
                                } else {
                                    $orderOut['line_items'][$bundleIndex]['price'] += $bundlePrice;
                                }
                            }
                        }
                        if(isset($selection['qty'])) {
                            $itemData['qty_ordered'] = $selection['qty'];
                        }
                        if($itemData['tax_percent'] == "0.0000") {
                            $itemData['tax_percent'] = $parentPercent;
                        }
                        if($itemData['tax_amount'] == "0.0000") {
                            $subTotal = (int)$itemData['qty_ordered'] * (float)$itemData['price'];
                            $vatComponent = ($subTotal / 100) * $itemData['tax_percent'];
                            $itemData['tax_amount'] = $vatComponent;
                        }
                        break;
                }
                $orderOut['line_items'][] = $itemData;
            }
            $itemCount++;
        }

        /** @var $customer Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')->load($orderIn->getCustomerId());
        $orderOut['customer'] = $customer->getData();
        $orderOut['customer']['customer_id'] = $orderIn->getCustomerId();

        /** @var $shipping_address Mage_Sales_Model_Order_Address*/
        $shipping_address = $orderIn->getShippingAddress();
        $orderOut['shipping_address'] = $shipping_address->getData();

        /** @var $shipping_address Mage_Sales_Model_Order_Address*/
        $billing_address = $orderIn->getBillingAddress();
        $orderOut['billing_address'] = $billing_address->getData();

        /** @var $shipping_address Mage_Sales_Model_Order_Payment*/
        $payment = $orderIn->getPayment()->getData();

        // remove cc fields
        foreach ($payment as $key => $value) {
            if (strpos($key, 'cc_') !== 0) {
                $orderOut['payment'][$key] = $value;
            }
        }

        /** @var $orderOut Mage_Core_Model_Session */
        $session = Mage::getModel('core/session');
        $orderOut['visitor'] = $session->getValidatorData();
        return $orderOut;
    }
}