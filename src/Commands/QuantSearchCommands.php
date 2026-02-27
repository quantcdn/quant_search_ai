<?php

namespace Drupal\quantsearch_ai\Commands;

use Drupal\quantsearch_ai\Client\QuantSearchClient;
use Drupal\quantsearch_ai\Service\IndexingService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for QuantSearch AI.
 */
class QuantSearchCommands extends DrushCommands {

  /**
   * The indexing service.
   *
   * @var \Drupal\quantsearch_ai\Service\IndexingService
   */
  protected $indexingService;

  /**
   * The QuantSearch client.
   *
   * @var \Drupal\quantsearch_ai\Client\QuantSearchClient
   */
  protected $client;

  /**
   * Constructs the commands.
   *
   * @param \Drupal\quantsearch_ai\Service\IndexingService $indexing_service
   *   The indexing service.
   * @param \Drupal\quantsearch_ai\Client\QuantSearchClient $client
   *   The QuantSearch client.
   */
  public function __construct(IndexingService $indexing_service, QuantSearchClient $client) {
    parent::__construct();
    $this->indexingService = $indexing_service;
    $this->client = $client;
  }

  /**
   * Queue all content for indexing to QuantSearch.
   *
   * @command quantsearch:index
   * @aliases qs-index
   * @usage quantsearch:index
   *   Queue all configured content types for indexing.
   */
  public function index() {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured. Please connect to QuantSearch first.');
      return;
    }

    $count = $this->indexingService->queueFullIndex();
    $this->logger()->success(dt('Queued @count nodes for indexing.', ['@count' => $count]));
  }

  /**
   * Process the QuantSearch indexing queue.
   *
   * @param array $options
   *   Command options.
   *
   * @command quantsearch:process-queue
   * @aliases qs-process
   * @option limit Maximum items to process (default: 100)
   * @usage quantsearch:process-queue --limit=50
   *   Process up to 50 items from the queue.
   */
  public function processQueue(array $options = ['limit' => 100]) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured. Please connect to QuantSearch first.');
      return;
    }

    $limit = (int) $options['limit'];
    $processed = $this->indexingService->processQueue($limit);
    $this->logger()->success(dt('Processed @count items.', ['@count' => $processed]));

    $remaining = $this->indexingService->getQueueSize();
    if ($remaining > 0) {
      $this->logger()->notice(dt('@count items remaining in queue.', ['@count' => $remaining]));
    }
  }

  /**
   * Show queue status.
   *
   * @command quantsearch:queue-status
   * @aliases qs-queue
   * @usage quantsearch:queue-status
   *   Show the number of items in the indexing queue.
   */
  public function queueStatus() {
    $count = $this->indexingService->getQueueSize();
    $this->output()->writeln(dt('Items in queue: @count', ['@count' => $count]));
  }

  /**
   * Trigger a full site crawl via QuantSearch.
   *
   * @param array $options
   *   Command options.
   *
   * @command quantsearch:crawl
   * @aliases qs-crawl
   * @option max-pages Maximum pages to crawl (default: 100)
   * @usage quantsearch:crawl --max-pages=500
   *   Start a crawl with up to 500 pages.
   */
  public function crawl(array $options = ['max-pages' => 100]) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured. Please connect to QuantSearch first.');
      return;
    }

    $max_pages = (int) $options['max-pages'];
    $job_id = $this->client->triggerCrawl([
      'maxPages' => $max_pages,
    ]);

    if ($job_id) {
      $this->logger()->success(dt('Crawl started. Job ID: @id', ['@id' => $job_id]));
      $this->output()->writeln(dt('Use "drush qs-status @id" to check progress.', ['@id' => $job_id]));
    }
    else {
      $this->logger()->error('Failed to start crawl. Check the logs for details.');
    }
  }

  /**
   * Check crawl job status.
   *
   * @param string $job_id
   *   The job ID to check.
   *
   * @command quantsearch:crawl-status
   * @aliases qs-status
   * @usage quantsearch:crawl-status abc123
   *   Check the status of crawl job abc123.
   */
  public function crawlStatus(string $job_id) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured.');
      return;
    }

    $status = $this->client->getCrawlStatus($job_id);

    if (!$status) {
      $this->logger()->error('Job not found or failed to get status.');
      return;
    }

    $this->io()->table(
      ['Property', 'Value'],
      [
        ['Status', $status['status'] ?? 'unknown'],
        ['Pages Discovered', $status['pagesDiscovered'] ?? 0],
        ['Pages Crawled', $status['pagesCrawled'] ?? 0],
        ['Pages Indexed', $status['pagesProcessed'] ?? 0],
        ['Errors', $status['pagesErrored'] ?? 0],
      ]
    );
  }

  /**
   * Purge all indexed content from QuantSearch.
   *
   * @command quantsearch:purge
   * @aliases qs-purge
   * @usage quantsearch:purge
   *   Purge the entire search index.
   */
  public function purge() {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured.');
      return;
    }

    if (!$this->io()->confirm('Are you sure you want to purge the entire search index? This cannot be undone.')) {
      $this->logger()->notice('Purge cancelled.');
      return;
    }

    $success = $this->client->purgeIndex();

    if ($success) {
      $this->logger()->success('Search index purged successfully.');
    }
    else {
      $this->logger()->error('Failed to purge index. Check the logs for details.');
    }
  }

}
