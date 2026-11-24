<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces only NovaPay's HTTP transport in isolated browser test sites.
 */
final class CommerceNovapayTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $transport = new Definition(Client::class);
    $transport->setFactory([self::class, 'createClient']);
    $transport->setArguments([new Reference('state')]);
    $container->setDefinition('commerce_novapay_test.http_client', $transport);
    $container->getDefinition('commerce_novapay.api_client')
      ->replaceArgument(0, new Reference('commerce_novapay_test.http_client'));
  }

  /**
   * Records test payloads without accessing a network.
   */
  public static function createClient(StateInterface $state): Client {
    return new Client([
      'handler' => static function (RequestInterface $request) use ($state): FulfilledPromise {
        $path = $request->getUri()->getPath();
        if (!in_array($path, ['/v1/complete-hold', '/v1/void'], TRUE)) {
          throw new \LogicException('Unexpected NovaPay request in capture test.');
        }
        $key = $path === '/v1/void'
          ? 'commerce_novapay_test.refunds'
          : 'commerce_novapay_test.captures';
        $requests = $state->get($key, []);
        $requests[] = json_decode((string) $request->getBody(), TRUE, 32, JSON_THROW_ON_ERROR);
        $state->set($key, $requests);
        return new FulfilledPromise(new Response(200, [], '{}'));
      },
    ]);
  }

}
