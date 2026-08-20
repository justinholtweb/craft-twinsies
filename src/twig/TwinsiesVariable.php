<?php

namespace justinholtweb\twinsies\twig;

use craft\commerce\elements\Order;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\Plugin;

/**
 * `craft.twinsies` — read-only, for order confirmation pages and customer account areas.
 *
 * Everything here is a lookup. Nothing on the front end may post to Twinfield.
 */
class TwinsiesVariable
{
    /**
     * The invoice document for an order, if there is one.
     */
    public function document(Order|int|null $order, string $sourceKey = Document::KIND_INVOICE): ?Document
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if (!$orderId) {
            return null;
        }

        return Plugin::getInstance()->getSync()->getDocument((int)$orderId, $sourceKey);
    }

    /**
     * Every document for an order, invoice and credit notes alike.
     *
     * @return Document[]
     */
    public function documents(Order|int|null $order): array
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if (!$orderId) {
            return [];
        }

        return Plugin::getInstance()->getSync()->getDocumentsForOrder((int)$orderId);
    }

    /**
     * Whether Twinfield has this order's invoice, and reports it paid.
     */
    public function isPaid(Order|int|null $order): bool
    {
        return (bool)$this->document($order)?->isPaid();
    }

    /**
     * The Twinfield reference for an order — `FACTUUR 2026001` — for printing on a receipt.
     */
    public function reference(Order|int|null $order): ?string
    {
        $document = $this->document($order);

        return $document?->isSent() ? $document->getReference() : null;
    }

    /**
     * Whether Twinsies is connected and configured. Handy for conditionally showing a panel.
     */
    public function isConnected(): bool
    {
        return Plugin::getInstance()->getAuth()->isConnected();
    }
}
