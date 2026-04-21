<?php

namespace Civi\ACHOffline;

use Civi\Checkout\CheckoutOptionUtils;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @service civi.achoffline.checkoutOptions
 */
class CheckoutOptions extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      'civi.checkout.options' => 'getCheckoutOptions',
    ];
  }

  public function getCheckoutOptions(GenericHookEvent $e): void {
    $authnetPairs = CheckoutOptionUtils::getPaymentProcessorPairs(['ACHOffline']);

    foreach ($authnetPairs as $name => $pair) {
      $e->options["achoffline_{$name}"] = new CheckoutOption\ACHOffline($pair['live'], $pair['test']);
      $e->options["achoffline_token_{$name}"] = new CheckoutOption\PaymentToken($pair['live'], $pair['test']);
    }
  }

}