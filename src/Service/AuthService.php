<?php

namespace Drupal\quantsearch_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service for managing QuantSearch authentication.
 */
class AuthService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs the AuthService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    KeyRepositoryInterface $key_repository
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->keyRepository = $key_repository;
  }

  /**
   * Gets the API key from Key module.
   *
   * @return string|null
   *   The API key or NULL if not configured.
   */
  public function getApiKey(): ?string {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $key_id = $config->get('api_key_id');

    if (!$key_id) {
      return NULL;
    }

    $key = $this->keyRepository->getKey($key_id);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Checks if the module is connected to QuantSearch.
   *
   * @return bool
   *   TRUE if connected.
   */
  public function isConnected(): bool {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    return !empty($config->get('api_key_id')) && !empty($config->get('site_id'));
  }

  /**
   * Gets connection details.
   *
   * @return array
   *   Connection details array.
   */
  public function getConnectionDetails(): array {
    $config = $this->configFactory->get('quantsearch_ai.settings');

    return [
      'connected' => $this->isConnected(),
      'org_id' => $config->get('org_id'),
      'org_name' => $config->get('org_name'),
      'site_id' => $config->get('site_id'),
      'site_name' => $config->get('site_name'),
      'base_url' => $config->get('base_url'),
    ];
  }

  /**
   * Validates the current API key by making a test request.
   *
   * @return bool
   *   TRUE if valid.
   */
  public function validateApiKey(): bool {
    $api_key = $this->getApiKey();
    if (!$api_key) {
      return FALSE;
    }

    $config = $this->configFactory->get('quantsearch_ai.settings');
    $api_endpoint = $config->get('api_endpoint') ?: 'https://quantsearch.ai/api';

    try {
      $response = $this->httpClient->get($api_endpoint . '/sites', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
      ]);

      return $response->getStatusCode() === 200;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
