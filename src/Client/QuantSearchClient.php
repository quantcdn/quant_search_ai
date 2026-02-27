<?php

namespace Drupal\quantsearch_ai\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP client for QuantSearch API.
 */
class QuantSearchClient {

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a QuantSearchClient.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('quantsearch_ai');
    $this->keyRepository = $key_repository;
  }

  /**
   * Gets the API key from Key module.
   *
   * @return string|null
   *   The API key or NULL if not configured.
   */
  protected function getApiKey(): ?string {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $key_id = $config->get('api_key_id');

    if (!$key_id) {
      return NULL;
    }

    $key = $this->keyRepository->getKey($key_id);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Builds headers for API requests.
   *
   * @return array
   *   The headers array.
   */
  protected function getHeaders(): array {
    $api_key = $this->getApiKey();
    $config = $this->configFactory->get('quantsearch_ai.settings');

    return [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $api_key,
    ];
  }

  /**
   * Gets the API base URL.
   *
   * @return string
   *   The API base URL.
   */
  protected function getBaseUrl(): string {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    return $config->get('api_endpoint') ?: 'https://quantsearch.ai/api';
  }

  /**
   * Gets the site ID.
   *
   * @return string|null
   *   The site ID or NULL.
   */
  protected function getSiteId(): ?string {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    return $config->get('site_id');
  }

  /**
   * Checks if the client is configured.
   *
   * @return bool
   *   TRUE if configured, FALSE otherwise.
   */
  public function isConfigured(): bool {
    return !empty($this->getApiKey()) && !empty($this->getSiteId());
  }

  /**
   * Ingests pages to the search index.
   *
   * @param array $pages
   *   Array of page data to index.
   * @param bool $wait
   *   Whether to wait for processing to complete. Default FALSE for single
   *   pages (fire-and-forget), TRUE for batches to avoid overwhelming the API.
   *
   * @return array
   *   The API response.
   *
   * @throws \Exception
   *   If the request fails.
   */
  public function ingestPages(array $pages, bool $wait = NULL): array {
    $site_id = $this->getSiteId();
    if (!$site_id) {
      throw new \Exception('Site ID not configured');
    }

    // Auto-determine wait mode: wait for batches, fire-and-forget for single pages
    if ($wait === NULL) {
      $wait = count($pages) > 1;
    }

    $url = $this->getBaseUrl() . '/sites/' . $site_id . '/pages';
    if ($wait) {
      $url .= '?wait=true';
    }

    try {
      $response = $this->httpClient->post($url, [
        'headers' => $this->getHeaders(),
        'json' => ['pages' => $pages],
        // Longer timeout for batch processing with wait=true
        'timeout' => $wait ? 300 : 30,
      ]);

      return json_decode($response->getBody()->getContents(), TRUE) ?: [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('QuantSearch ingest failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to ingest pages: ' . $e->getMessage());
    }
  }

  /**
   * Deletes pages from the index.
   *
   * @param array $urls
   *   Array of URLs to delete.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If the request fails.
   */
  public function deletePages(array $urls): bool {
    $site_id = $this->getSiteId();
    if (!$site_id) {
      throw new \Exception('Site ID not configured');
    }

    $url = $this->getBaseUrl() . '/sites/' . $site_id . '/pages';

    try {
      $response = $this->httpClient->delete($url, [
        'headers' => $this->getHeaders(),
        'json' => ['urls' => $urls],
        'timeout' => 30,
      ]);

      return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }
    catch (GuzzleException $e) {
      $this->logger->error('QuantSearch delete failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to delete pages: ' . $e->getMessage());
    }
  }

  /**
   * Triggers a full site crawl.
   *
   * @param array $options
   *   Crawl options (maxPages, etc.).
   *
   * @return string|null
   *   The job ID or NULL on failure.
   */
  public function triggerCrawl(array $options = []): ?string {
    $site_id = $this->getSiteId();
    if (!$site_id) {
      throw new \Exception('Site ID not configured');
    }

    $url = $this->getBaseUrl() . '/sites/' . $site_id . '/crawl';

    try {
      $response = $this->httpClient->post($url, [
        'headers' => $this->getHeaders(),
        'json' => $options,
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);
      return $result['jobId'] ?? $result['job_id'] ?? NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('QuantSearch crawl failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets crawl job status.
   *
   * @param string $job_id
   *   The job ID.
   *
   * @return array|null
   *   The job status or NULL.
   */
  public function getCrawlStatus(string $job_id): ?array {
    $site_id = $this->getSiteId();
    if (!$site_id) {
      return NULL;
    }

    $url = $this->getBaseUrl() . '/sites/' . $site_id . '/crawl/' . $job_id;

    try {
      $response = $this->httpClient->get($url, [
        'headers' => $this->getHeaders(),
        'timeout' => 30,
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->logger->error('QuantSearch crawl status failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Lists available sites for the organization.
   *
   * @return array
   *   Array of sites.
   */
  public function listSites(): array {
    $url = $this->getBaseUrl() . '/sites';

    try {
      $response = $this->httpClient->get($url, [
        'headers' => $this->getHeaders(),
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);
      return $result['sites'] ?? $result ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('QuantSearch list sites failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Purges the entire search index.
   *
   * @return bool
   *   TRUE on success.
   */
  public function purgeIndex(): bool {
    $site_id = $this->getSiteId();
    if (!$site_id) {
      return FALSE;
    }

    $url = $this->getBaseUrl() . '/sites/' . $site_id . '/purge';

    try {
      $response = $this->httpClient->post($url, [
        'headers' => $this->getHeaders(),
        'timeout' => 60,
      ]);

      return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }
    catch (GuzzleException $e) {
      $this->logger->error('QuantSearch purge failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
